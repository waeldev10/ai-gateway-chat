<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class ApiResponse
{
    public static function resource(
        JsonResource|array $resource,
        ?string $message = null,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return self::success($resource, $message, $status, $headers);
    }

    /**
     * @param  ResourceCollection|array<int|string, mixed>  $collection
     * @param  array<string, mixed>  $headers
     */
    public static function collection(
        ResourceCollection|array $collection,
        ?string $message = null,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return self::success($collection, $message, $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function paginated(
        ResourceCollection $collection,
        ?string $message = null,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        $payload = $collection->response()->getData(true);

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function action(
        ?string $message = null,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return self::success(null, $message, $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $extra  Additional Problem Details members such as `errors` or `retry_after`.
     * @param  array<string, mixed>  $headers
     */
    public static function problem(
        int $status,
        string $title,
        string $detail,
        ?string $code = null,
        array $extra = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json([...$payload, ...$extra], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private static function success(
        mixed $data,
        ?string $message,
        int $status,
        array $headers,
    ): JsonResponse {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status, $headers);
    }
}
