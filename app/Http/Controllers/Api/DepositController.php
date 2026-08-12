<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Concerns\ReadsMultipart;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\DepositDay;
use App\Models\DepositProof;
use App\Support\AuditLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/*
 * Deposits: the record a bank slip becomes. The expected figure a deposit is
 * matched against follows the house rule — profit minus expenses; the
 * capital share of the takings stays in the shop to restock. Not all of it
 * arrives as cash: GCash and bank-transfer sales land in the account without
 * touching the drawer, so the manager declares that `online` figure alongside
 * the slip and the match is judged on cash PLUS online against expected.
 * One slip photo covers one deposit, ever: the backend hashes the file
 * itself and refuses a repeat with 409, whatever the client claimed.
 */
class DepositController extends Controller
{
    use ReadsBranchScope, ReadsMultipart;

    public function __construct(private readonly AuditLedger $ledger) {}

    /** GET /api/deposits?storeId=…&from=…&to=… */
    public function index(Request $request): JsonResponse
    {
        $storeId = $this->requireStoreRange($request);

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        $deposits = Deposit::query()
            ->with('days')
            ->where('store_id', $storeId)
            ->whereBetween('day', [$request->query('from'), $request->query('to')])
            ->orderByDesc('day')
            ->get();

        return response()->json($deposits->map(fn (Deposit $deposit) => $deposit->toWire()));
    }

    /** GET /api/deposits/pending?storeId=… — audited days still uncovered, oldest first */
    public function pending(Request $request): JsonResponse
    {
        $request->validate(['storeId' => ['required', 'string']]);
        $storeId = (string) $request->query('storeId');

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        return response()->json($this->ledger->pending($storeId));
    }

    /** POST /api/deposits — multipart: payload JSON, slip[] photo, discrepancyProof[] */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        Validator::make($payload, [
            'storeId' => ['required', 'string'],
            'day' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'online' => ['sometimes', 'numeric', 'gte:0'],
            'reference' => ['required', 'string', 'max:120'],
            'covers' => ['required', 'array', 'min:1'],
            'covers.*' => ['required', 'date_format:Y-m-d'],
            'discrepancyReason' => ['sometimes', 'string', 'max:1000'],
        ])->validate();

        if (! $this->allowed($request->user(), [$payload['storeId']])) {
            return $this->forbidden();
        }

        $slip = $this->files($request, 'slip')[0] ?? null;
        if ($slip === null) {
            return $this->fieldErrors(['slip' => 'Attach the deposit slip photo.']);
        }

        /* The one-photo-one-deposit rule is the backend's: the file itself is
           hashed here, whatever fingerprint the client offered */
        $sha = hash_file('sha256', $slip->getRealPath());
        if ($sha !== false && Deposit::query()->where('slip_sha', $sha)->exists()) {
            return $this->fieldErrors(
                ['slip' => 'This photo is already filed. Take a photo of the new slip.'],
                409,
                'This slip photo already covers a deposit.',
            );
        }

        /* Every covered day must actually be waiting: audited, past, and not
           already covered — the pending list is the single source of that */
        $pending = $this->ledger->pending($payload['storeId'])->keyBy('day');
        $covers = array_values(array_unique($payload['covers']));

        /* The owner's batching window is a rule, not a suggestion: one slip
           may cover only so many days before the money must reach the bank */
        $window = ReconciliationController::batchWindowDays();
        if (count($covers) > $window) {
            $extra = count($covers) - $window;

            return $this->fieldErrors(
                ['days' => "Unselect {$extra} ".($extra === 1 ? 'day' : 'days').', or record this as more than one deposit.'],
                422,
                "One deposit may cover at most {$window} ".($window === 1 ? 'day' : 'days').'.',
            );
        }
        foreach ($covers as $day) {
            if (! $pending->has($day)) {
                return response()->json(
                    ['message' => "{$day} is not waiting for a deposit. It is still open, or already covered."],
                    422,
                );
            }
        }

        $expected = round(
            collect($covers)->sum(fn (string $day) => (float) $pending->get($day)['expected']),
            2,
        );
        $amount = round((float) $payload['amount'], 2);
        $online = round((float) ($payload['online'] ?? 0), 2);
        // Cash on the slip plus what came in online, in centavos, against expected
        $matched = (int) round($amount * 100) + (int) round($online * 100)
            === (int) round($expected * 100);

        if (! $matched && trim((string) ($payload['discrepancyReason'] ?? '')) === '') {
            return $this->fieldErrors([
                'reason' => "Explain the {$this->pesos(abs($amount + $online - $expected))} difference before recording this.",
            ]);
        }

        $deposit = $this->record($request, $payload, $slip, $sha, $covers, $amount, $online, $expected, $matched);

        return response()->json($deposit->toWire());
    }

    /**
     * Persist what the guards above already accepted: the deposit row, one
     * DepositDay per covered day, and any discrepancy-proof photos.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $covers
     */
    private function record(
        Request $request,
        array $payload,
        UploadedFile $slip,
        string|false $sha,
        array $covers,
        float $amount,
        float $online,
        float $expected,
        bool $matched,
    ): Deposit {
        $slipPath = $slip->store("receipts/slips/{$payload['storeId']}");
        $deposit = Deposit::query()->create([
            'store_id' => $payload['storeId'],
            'day' => $payload['day'],
            'amount' => $amount,
            'online' => $online,
            'expected' => $expected,
            'reference' => trim((string) $payload['reference']),
            'slip_path' => (string) $slipPath,
            'slip_sha' => $sha === false ? null : $sha,
            'matched' => $matched,
            'discrepancy_reason' => $matched ? null : trim((string) $payload['discrepancyReason']),
        ]);

        foreach ($covers as $day) {
            DepositDay::query()->create([
                'deposit_id' => $deposit->id,
                'store_id' => $payload['storeId'],
                'day' => $day,
            ]);
        }
        foreach ($this->files($request, 'discrepancyProof') as $proofFile) {
            $path = $proofFile->store("receipts/slips/{$deposit->id}/proof");
            if ($path !== false) {
                DepositProof::query()->create(['deposit_id' => $deposit->id, 'path' => $path]);
            }
        }

        return $deposit->load('days');
    }

    private function pesos(float $value): string
    {
        return '₱'.number_format($value, 2);
    }
}
