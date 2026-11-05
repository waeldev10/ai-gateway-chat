# Authentication API v1

All authentication endpoints are available under `/api/v1/auth`. The API accepts and returns JSON. Architecture and conventions are documented in [`memory/API.md`](../../memory/API.md).

- Send `Accept: application/json` on every request.
- Send `Content-Type: application/json` for requests with a body.
- API clients authenticate with a Laravel Sanctum personal access token using the `Authorization: Bearer {access_token}` header.
- Validation messages are returned in Arabic. The API sets the request locale to Arabic without changing the Web application locale.

## Token model

- Registration and login issue a Sanctum personal access token.
- The plain-text token is returned only once, in the `access_token` field of the response.
- Tokens are stored hashed by Sanctum. The application never returns the stored hash.
- The optional `device_name` request field sets the token name. It defaults to `api-token`.
- Tokens do not expire by default because `config/sanctum.php` has `expiration` set to `null`.
- `POST /api/v1/auth/logout` revokes the token used for the current request.
- `POST /api/v1/auth/logout-all` revokes every token belonging to the authenticated user.

## Success response format

Responses use a consistent `data` envelope. `message` is included only when it adds meaning to the operation.

```json
{
  "data": {
    "user": {
      "id": "01jz9m0r6v8x3k4p5q7s9t2w6a",
      "name": "Example User",
      "email": "user@example.com"
    },
    "access_token": "1|examplePlainTextTokenThatIsOnlyReturnedOnce",
    "token_type": "Bearer"
  },
  "message": "تم تسجيل الدخول بنجاح."
}
```

For operations without a resource payload:

```json
{
  "data": null,
  "message": "تم تسجيل الخروج بنجاح."
}
```

## Error response format

Errors use an RFC 9457 Problem Details structure adapted for this API. Validation messages inside `errors` are Arabic.

```json
{
  "type": "about:blank",
  "title": "Validation Failed",
  "status": 422,
  "detail": "بيانات الطلب غير صحيحة.",
  "code": "validation_failed",
  "errors": {
    "email": [
      "البريد الإلكتروني مطلوب."
    ]
  }
}
```

Common error codes:

| HTTP status | `code` | Meaning |
|---:|---|---|
| 400 | `bad_request` | The request could not be processed. |
| 401 | `unauthenticated` | A valid Sanctum bearer token is required. |
| 403 | `forbidden` | The authenticated user may not perform the action. |
| 404 | `not_found` | The API endpoint or requested model was not found. |
| 405 | `method_not_allowed` | The HTTP method is not supported for the endpoint. |
| 422 | `validation_failed` | Request validation failed. `errors` is present. |
| 429 | `rate_limited` | A rate limit was exceeded. `retry_after` is present when available. |
| 500 | `server_error` | An unexpected server error occurred. Internal details are not exposed. |

## Rate limiting

Public authentication endpoints are rate limited with Laravel's built-in rate limiter. Keys combine the normalized email (lowercase, trimmed) and the client IP address where appropriate.

| Endpoint | Limit |
|---|---|
| `POST /api/v1/auth/register` | 5 requests per minute per IP |
| `POST /api/v1/auth/login` | 5 requests per minute per normalized email and IP; 20 requests per minute per IP |
| Other API routes | 60 requests per minute per authenticated user, or per IP for unauthenticated requests |

When a limit is exceeded the API returns HTTP 429 with the Problem Details contract, a `retry_after` member when the delay is known, and rate-limit headers such as `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`.

## Endpoints

### Register

`POST /api/v1/auth/register` (public)

Creates a user and issues an access token.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `name` | string | yes | Maximum 255 characters. |
| `email` | string | yes | Valid email address, maximum 255 characters, unique. Normalized to lowercase. |
| `password` | string | yes | Minimum 8 characters. Must match `password_confirmation`. |
| `password_confirmation` | string | yes | Must match `password`. |
| `device_name` | string | no | Token name, 1-255 characters. Defaults to `api-token`. |

Example request:

```json
{
  "name": "Example User",
  "email": "user@example.com",
  "password": "password",
  "password_confirmation": "password",
  "device_name": "mobile-app"
}
```

Success: `201 Created`

```json
{
  "data": {
    "user": {
      "id": "01jz9m0r6v8x3k4p5q7s9t2w6a",
      "name": "Example User",
      "email": "user@example.com"
    },
    "access_token": "1|examplePlainTextTokenThatIsOnlyReturnedOnce",
    "token_type": "Bearer"
  },
  "message": "تم تسجيل الحساب بنجاح."
}
```

Errors: `422 validation_failed`, `429 rate_limited`.

### Login

`POST /api/v1/auth/login` (public)

Validates credentials and issues an access token. Invalid credentials return `422` with an `email` validation error; the response does not reveal whether the email exists.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `email` | string | yes | Valid email address, maximum 255 characters. Normalized to lowercase. |
| `password` | string | yes | Required string. |
| `device_name` | string | no | Token name, 1-255 characters. Defaults to `api-token`. |

Example request:

```json
{
  "email": "user@example.com",
  "password": "password",
  "device_name": "web-client"
}
```

Success: `200 OK`

```json
{
  "data": {
    "user": {
      "id": "01jz9m0r6v8x3k4p5q7s9t2w6a",
      "name": "Example User",
      "email": "user@example.com"
    },
    "access_token": "2|examplePlainTextTokenThatIsOnlyReturnedOnce",
    "token_type": "Bearer"
  },
  "message": "تم تسجيل الدخول بنجاح."
}
```

Invalid credentials: `422 Unprocessable Content`

```json
{
  "type": "about:blank",
  "title": "Validation Failed",
  "status": 422,
  "detail": "بيانات الطلب غير صحيحة.",
  "code": "validation_failed",
  "errors": {
    "email": [
      "بيانات الاعتماد غير صحيحة."
    ]
  }
}
```

Other errors: `429 rate_limited`.

### Logout

`POST /api/v1/auth/logout` (Sanctum)

Revokes the personal access token used for the current request. Other tokens remain valid.

Required header:

```
Authorization: Bearer {access_token}
```

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم تسجيل الخروج بنجاح."
}
```

Errors: `401 unauthenticated`.

### Logout all

`POST /api/v1/auth/logout-all` (Sanctum)

Revokes every Sanctum token belonging to the authenticated user, including the current token. Tokens belonging to other users are unaffected.

Required header:

```
Authorization: Bearer {access_token}
```

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم إلغاء جميع رموز الوصول بنجاح."
}
```

Errors: `401 unauthenticated`.

### Get authenticated user

`GET /api/v1/auth/me` (Sanctum)

Returns the authenticated user. Only public fields are returned.

Required header:

```
Authorization: Bearer {access_token}
```

Success: `200 OK`

```json
{
  "data": {
    "id": "01jz9m0r6v8x3k4p5q7s9t2w6a",
    "name": "Example User",
    "email": "user@example.com"
  }
}
```

Errors: `401 unauthenticated`.

### Update profile

`PATCH /api/v1/auth/profile` or `PUT /api/v1/auth/profile` (Sanctum)

Updates the authenticated user's `name` and/or `email`. Both fields are optional, but when provided they are validated. Changing the email clears `email_verified_at`.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `name` | string | no | Maximum 255 characters. |
| `email` | string | no | Valid email address, maximum 255 characters, unique. Normalized to lowercase. The authenticated user's current email is allowed. |

Example request:

```json
{
  "name": "Updated User",
  "email": "updated@example.com"
}
```

Success: `200 OK`

```json
{
  "data": {
    "id": "01jz9m0r6v8x3k4p5q7s9t2w6a",
    "name": "Updated User",
    "email": "updated@example.com"
  },
  "message": "تم تحديث الملف الشخصي بنجاح."
}
```

Errors: `401 unauthenticated`, `422 validation_failed`.

### Change password

`PUT /api/v1/auth/password` or `PATCH /api/v1/auth/password` (Sanctum)

Changes the authenticated user's password after verifying `current_password`. The new password is hashed using the application's hashing configuration.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `current_password` | string | yes | Must match the authenticated user's current password. |
| `password` | string | yes | Minimum 8 characters. Must match `password_confirmation`. |
| `password_confirmation` | string | yes | Must match `password`. |

Example request:

```json
{
  "current_password": "password",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم تغيير كلمة المرور بنجاح."
}
```

Invalid current password: `422 Unprocessable Content`

```json
{
  "type": "about:blank",
  "title": "Validation Failed",
  "status": 422,
  "detail": "بيانات الطلب غير صحيحة.",
  "code": "validation_failed",
  "errors": {
    "current_password": [
      "كلمة المرور غير صحيحة."
    ]
  }
}
```

Other errors: `401 unauthenticated`, `422 validation_failed`.

## Security notes

- Password hashes and plain-text tokens are never written to logs by this API.
- Only `name`, `email`, and `password` are mass assignable on `User`.
- User resources expose only `id`, `name`, and `email`.
- `AuthResource` is returned only when register or login issues a token; profile and current-user responses never expose access tokens.
- Registration and login are rate limited.
- Invalid login credentials use the same message for unknown emails and incorrect passwords.
- Password changes verify the current password before updating the hash; an incorrect current password returns a `current_password` validation error.
- Changing the email address clears `email_verified_at`.
- Protected endpoints always require `auth:sanctum`; they never fall back to unauthenticated access.
- Unhandled server errors never expose internal exception details.
