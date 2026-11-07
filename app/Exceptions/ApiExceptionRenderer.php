<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $this->isApiRequest($request) || $exception instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => ApiResponse::problem(
                422,
                'Validation Failed',
                'بيانات الطلب غير صحيحة.',
                'validation_failed',
                ['errors' => $exception->errors()],
            ),
            $exception instanceof AuthenticationException => ApiResponse::problem(
                401,
                'Unauthenticated',
                'Unauthenticated.',
                'unauthenticated',
            ),
            $exception instanceof AuthorizationException => ApiResponse::problem(
                403,
                'Forbidden',
                'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
                'forbidden',
            ),
            $exception instanceof ModelNotFoundException => ApiResponse::problem(
                404,
                'Not Found',
                'المورد المطلوب غير موجود.',
                'not_found',
            ),
            $exception instanceof HttpExceptionInterface => $this->renderHttpException($exception),
            default => ApiResponse::problem(
                500,
                'Server Error',
                'حدث خطأ غير متوقع. يرجى المحاولة لاحقًا.',
                'server_error',
            ),
        };
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->is('api', 'api/*');
    }

    private function renderHttpException(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();
        $headers = $exception->getHeaders();

        [$title, $detail, $code] = match ($status) {
            400 => ['Bad Request', 'تعذر معالجة الطلب.', 'bad_request'],
            401 => ['Unauthenticated', 'Unauthenticated.', 'unauthenticated'],
            403 => ['Forbidden', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.', 'forbidden'],
            404 => ['Not Found', 'المسار المطلوب غير موجود.', 'not_found'],
            405 => ['Method Not Allowed', 'طريقة HTTP غير مدعومة لهذا المسار.', 'method_not_allowed'],
            429 => ['Too Many Requests', 'عدد الطلبات كبير جدًا. يرجى المحاولة لاحقًا.', 'rate_limited'],
            500 => ['Server Error', 'حدث خطأ غير متوقع. يرجى المحاولة لاحقًا.', 'server_error'],
            default => [Response::$statusTexts[$status] ?? 'HTTP Error', 'تعذر إكمال الطلب.', 'http_error'],
        };

        $extra = [];

        if ($status === 429 && is_numeric($retryAfter = $headers['Retry-After'] ?? null)) {
            $extra['retry_after'] = (int) $retryAfter;
        }

        return ApiResponse::problem($status, $title, $detail, $code, $extra, $headers);
    }
}
