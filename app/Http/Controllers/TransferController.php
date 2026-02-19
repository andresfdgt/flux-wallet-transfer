<?php

namespace App\Http\Controllers;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Http\Requests\TransferRequest;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TransferController extends Controller
{
    private TransferService $transferService;

    public function __construct(TransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    public function store(TransferRequest $request): JsonResponse
    {
        try {
            $transfer = $this->transferService->create($request->validated());

            return response()->json([
                'message' => 'Transfer completed successfully',
                'data' => $transfer,
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (InsufficientFundsException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
