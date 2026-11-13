# AI Gateway Chat API v1

The API is served under `/api/v1`. It accepts and returns JSON. The machine-readable contract is documented in [`openapi.yaml`](openapi.yaml). Architecture and implementation conventions are documented in [`memory/API.md`](../../memory/API.md).

## Overview

- Base path: `/api/v1`
- Content type: `application/json`
- Authentication: Laravel Sanctum personal access token sent as a Bearer token
- API locale: Arabic (`ar`), scoped to the API request without changing the Web application locale
- Resource identifiers: ULID strings
- Timestamps: ISO 8601 strings, for example `2026-11-13T10:15:30+00:00`

### Request conventions

Send these headers on every request:

```http
Accept: application/json
```

For requests with a body, also send:

```http
Content-Type: application/json
```

Authenticated requests must send the token issued by register or login:

```http
Authorization: Bearer {access_token}
```

### Public and authenticated endpoints

| Access | Endpoints |
|---|---|
| Public | `POST /api/v1/auth/register`, `POST /api/v1/auth/login` |
| Authenticated | All other `/api/v1` endpoints |

Protected endpoints always require `auth:sanctum`; they never fall back to unauthenticated access. Conversations and messages are restricted to the authenticated owner. The system default persona is visible to every authenticated user but cannot be modified.

## Response formats

### Success envelope

Successful responses use a consistent `data` envelope. `message` is included only when it adds meaning to the operation.

```json
{
  "data": {
    "user": {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
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

### Page-based pagination

Conversation listing and search use page-based pagination. Responses contain top-level `data`, `links`, and `meta`.

```json
{
  "data": [
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
      "title": "Planning a trip to Japan",
      "is_pinned": true,
      "pinned_at": "2026-11-12T09:00:00+00:00",
      "created_at": "2026-11-10T08:30:00+00:00",
      "updated_at": "2026-11-12T09:00:00+00:00"
    }
  ],
  "links": {
    "first": "https://example.test/api/v1/conversations?page=1",
    "last": "https://example.test/api/v1/conversations?page=2",
    "prev": null,
    "next": "https://example.test/api/v1/conversations?page=2"
  },
  "meta": {
    "path": "https://example.test/api/v1/conversations",
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "per_page": 15,
    "to": 15,
    "total": 25
  }
}
```

### Cursor-based pagination

Message listing uses cursor pagination. The newest page is returned first. Within each page, messages are returned in chronological order (oldest to newest). Follow `meta.next_cursor` or `links.next` to move toward older messages.

```json
{
  "data": [
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6B",
      "role": "user",
      "content": "What should I pack?",
      "status": "complete",
      "created_at": "2026-11-13T10:14:00+00:00",
      "updated_at": "2026-11-13T10:14:00+00:00"
    }
  ],
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": "https://example.test/api/v1/conversations/01JZ9M0R6V8X3K4P5Q7S9T2W6A/messages?cursor=eyJpZCI6..."
  },
  "meta": {
    "path": "https://example.test/api/v1/conversations/01JZ9M0R6V8X3K4P5Q7S9T2W6A/messages",
    "per_page": 25,
    "next_cursor": "eyJpZCI6...",
    "prev_cursor": null
  }
}
```

### Problem Details errors

Errors use an RFC 9457 Problem Details structure adapted for this API. Validation messages inside `errors` are Arabic.

```json
{
  "type": "about:blank",
  "title": "Validation Failed",
  "status": 422,
  "detail": "بيانات الطلب غير صحيحة.",
  "code": "validation_failed",
  "errors": {
    "content": [
      "المحتوى مطلوب."
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
| 409 | `conversation_busy` | Another operation is currently running on the conversation. |
| 422 | `validation_failed` | Request validation failed. `errors` is present. |
| 429 | `rate_limited` | A rate limit was exceeded. `retry_after` is present when available. |
| 500 | `server_error` | An unexpected server error occurred. Internal details are not exposed. |
| 502 | `provider_failed` | The configured AI provider could not produce a response. |

Other HTTP statuses use `http_error` with the original status code.

Conversation busy example (`409`):

```json
{
  "type": "about:blank",
  "title": "Conversation Busy",
  "status": 409,
  "detail": "هناك عملية أخرى قيد التنفيذ على هذه المحادثة. يرجى إعادة المحاولة لاحقاً.",
  "code": "conversation_busy"
}
```

AI provider failure example (`502`):

```json
{
  "type": "about:blank",
  "title": "AI Provider Error",
  "status": 502,
  "detail": "تعذر الحصول على رد من مزود الذكاء الاصطناعي. يرجى المحاولة لاحقًا.",
  "code": "provider_failed"
}
```

## Rate limiting

Keys use the normalized email (lowercase, trimmed) and client IP where appropriate. Authenticated general API limits are keyed by user ID.

| Limiter | Applies to | Limit |
|---|---|---:|
| `register` | `POST /api/v1/auth/register` | 5 requests per minute per IP |
| `login` (credentials) | `POST /api/v1/auth/login` | 5 requests per minute per normalized email and IP |
| `login` (IP guard) | `POST /api/v1/auth/login` | 20 requests per minute per IP |
| `api` | All protected API routes | 60 requests per minute per authenticated user, or per IP for unauthenticated requests |
| `api-ai` | `POST .../messages`, `POST .../messages/continue`, `PATCH|PUT .../messages/{message}`, `POST .../messages/{message}/regenerate` | 20 requests per minute per authenticated user |

AI message operations are also covered by the general `api` limiter, so the practical limit for those endpoints is 20 requests per minute.

When a limit is exceeded the API returns HTTP 429 with the Problem Details contract, a `retry_after` member when the delay is known, and rate-limit headers such as `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`.

## Authentication

### Token model

- Registration and login issue a Sanctum personal access token.
- The plain-text token is returned only once, in the `access_token` field of the response.
- Tokens are stored hashed by Sanctum. The application never returns the stored hash.
- The optional `device_name` request field sets the token name. It defaults to `api-token`.
- Tokens do not expire by default because `config/sanctum.php` has `expiration` set to `null`.
- `POST /api/v1/auth/logout` revokes the token used for the current request.
- `POST /api/v1/auth/logout-all` revokes every token belonging to the authenticated user.

### Register

`POST /api/v1/auth/register` (public)

Creates a user and issues an access token.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `name` | string | yes | Maximum 255 characters. |
| `email` | string | yes | Valid email address, maximum 255 characters, unique. Normalized to lowercase. |
| `password` | string | yes | Minimum 8 characters and must satisfy the application password defaults. Must match `password_confirmation`. |
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
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
      "name": "Example User",
      "email": "user@example.com"
    },
    "access_token": "1|examplePlainTextTokenThatIsOnlyReturnedOnce",
    "token_type": "Bearer"
  },
  "message": "تم تسجيل الحساب بنجاح."
}
```

Errors: `422 validation_failed`, `429 rate_limited`, `500 server_error`.

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
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
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

Other errors: `429 rate_limited`, `500 server_error`.

### Logout

`POST /api/v1/auth/logout` (authenticated)

Revokes the personal access token used for the current request. Other tokens remain valid.

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم تسجيل الخروج بنجاح."
}
```

Errors: `401 unauthenticated`, `429 rate_limited`, `500 server_error`.

### Logout all

`POST /api/v1/auth/logout-all` (authenticated)

Revokes every Sanctum token belonging to the authenticated user, including the current token. Tokens belonging to other users are unaffected.

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم إلغاء جميع رموز الوصول بنجاح."
}
```

Errors: `401 unauthenticated`, `429 rate_limited`, `500 server_error`.

## User Profile and Account Management

### Get authenticated user

`GET /api/v1/auth/me` (authenticated)

Returns the authenticated user. Only public fields are returned.

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "name": "Example User",
    "email": "user@example.com"
  }
}
```

Errors: `401 unauthenticated`, `429 rate_limited`, `500 server_error`.

### Update profile

`PATCH /api/v1/auth/profile` or `PUT /api/v1/auth/profile` (authenticated)

Updates the authenticated user's `name` and/or `email`. Both fields are optional, but when provided they are validated. Changing the email clears `email_verified_at`. Keeping the same email does not clear verification.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `name` | string | no | When provided, required and maximum 255 characters. |
| `email` | string | no | When provided, required, valid email address, maximum 255 characters, unique except for the current user's email. Normalized to lowercase. |

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
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "name": "Updated User",
    "email": "updated@example.com"
  },
  "message": "تم تحديث الملف الشخصي بنجاح."
}
```

Errors: `401 unauthenticated`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Change password

`PUT /api/v1/auth/password` or `PATCH /api/v1/auth/password` (authenticated)

Changes the authenticated user's password after verifying `current_password`. The new password is hashed using the application's hashing configuration.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `current_password` | string | yes | Must match the authenticated user's current password. |
| `password` | string | yes | Minimum 8 characters and must satisfy the application password defaults. Must match `password_confirmation`. |
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

Other errors: `401 unauthenticated`, `429 rate_limited`, `500 server_error`.

## Conversations

Conversation responses expose only conversation metadata. Messages are fetched through the message listing endpoint; there is no embedded `messages` array in conversation responses.

Conversation fields:

| Field | Type | Nullable | Description |
|---|---|---:|---|
| `id` | string (ULID) | no | Conversation identifier. |
| `title` | string | yes | Conversation title. It is auto-generated from the first user message when blank. |
| `is_pinned` | boolean | no | Whether the conversation is pinned. |
| `pinned_at` | string (ISO 8601) | yes | Pin timestamp, or `null` when not pinned. |
| `created_at` | string (ISO 8601) | yes | Creation timestamp. |
| `updated_at` | string (ISO 8601) | yes | Last update timestamp. |

All conversation endpoints require authentication and ownership. A conversation owned by another user returns `403 forbidden`. An unknown conversation ID returns `404 not_found`.

### List conversations

`GET /api/v1/conversations` (authenticated)

Lists only the authenticated user's conversations with page-based pagination. Pinned conversations come first, then conversations are ordered by `updated_at` descending and `id` descending.

Query parameters:

| Parameter | Type | Required | Rules |
|---|---|---:|---|
| `search` | string | no | Maximum 255 characters. Filters by conversation title. |
| `q` | string | no | Alias for `search`. Used only when `search` is absent. Maximum 255 characters. |
| `per_page` | integer | no | 1-100. Defaults to 15. |
| `page` | integer | no | Minimum 1. |

Example request:

```http
GET /api/v1/conversations?search=Japan&per_page=15&page=1
```

Success: `200 OK` with page-based pagination (`data`, `links`, `meta`).

```json
{
  "data": [
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
      "title": "Planning a trip to Japan",
      "is_pinned": true,
      "pinned_at": "2026-11-12T09:00:00+00:00",
      "created_at": "2026-11-10T08:30:00+00:00",
      "updated_at": "2026-11-12T09:00:00+00:00"
    }
  ],
  "links": {
    "first": "https://example.test/api/v1/conversations?search=Japan&page=1",
    "last": "https://example.test/api/v1/conversations?search=Japan&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "path": "https://example.test/api/v1/conversations",
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

Errors: `401 unauthenticated`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Search conversations

`GET /api/v1/conversations/search` (authenticated)

Behaves exactly like `GET /api/v1/conversations`, including pagination and ordering. Use the `q` or `search` query parameter to filter the authenticated user's conversations by title.

Example request:

```http
GET /api/v1/conversations/search?q=study&per_page=10
```

Success: `200 OK` with the same page-based pagination structure as conversation listing.

Errors: `401 unauthenticated`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Create conversation

`POST /api/v1/conversations` (authenticated)

Creates a conversation owned by the authenticated user. If no title is supplied, the conversation starts untitled and is titled from the first user message later.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `title` | string | no | Nullable. Maximum 255 characters. |

Example request:

```json
{
  "title": "Planning a trip"
}
```

Success: `201 Created`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "title": "Planning a trip",
    "is_pinned": false,
    "pinned_at": null,
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:00:00+00:00"
  },
  "message": "تم إنشاء المحادثة بنجاح."
}
```

Errors: `401 unauthenticated`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Get conversation

`GET /api/v1/conversations/{conversation}` (authenticated, owner)

Returns conversation metadata. Messages are not embedded.

Path parameters:

| Parameter | Type | Description |
|---|---|---|
| `conversation` | string (ULID) | Conversation identifier. |

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "title": "Planning a trip",
    "is_pinned": false,
    "pinned_at": null,
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:00:00+00:00"
  }
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

### Update conversation

`PATCH /api/v1/conversations/{conversation}` or `PUT /api/v1/conversations/{conversation}` (authenticated, owner)

Renames a conversation.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `title` | string | yes | Maximum 255 characters. |

Example request:

```json
{
  "title": "New title"
}
```

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "title": "New title",
    "is_pinned": false,
    "pinned_at": null,
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:05:00+00:00"
  },
  "message": "تم تحديث المحادثة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Delete conversation

`DELETE /api/v1/conversations/{conversation}` (authenticated, owner)

Deletes the conversation and its messages. AI request audit rows are retained with their conversation and message references cleared. Deletion fails with `409 conversation_busy` while another message operation holds the conversation lock.

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم حذف المحادثة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conversation_busy`, `429 rate_limited`, `500 server_error`.

### Bulk delete conversations

`DELETE /api/v1/conversations` (authenticated, owner)

Deletes up to 50 selected conversations and their messages in one transaction. The operation is all-or-nothing: if any ID is missing or belongs to another user, nothing is deleted and the API returns `403 forbidden`.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `ids` | array of strings | yes | 1-50 items. Each item must be a distinct valid ULID. |

Example request:

```json
{
  "ids": [
    "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "01JZ9M0R6V8X3K4P5Q7S9T2W6B"
  ]
}
```

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم حذف المحادثات المحددة بنجاح."
}
```

When exactly one conversation is deleted, the message is `تم حذف المحادثة بنجاح.`

Errors: `401 unauthenticated`, `403 forbidden`, `409 conversation_busy`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Pin conversation

`POST /api/v1/conversations/{conversation}/pin` (authenticated, owner)

Pins the conversation. Pinning an already pinned conversation does not change `pinned_at`.

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "title": "Planning a trip",
    "is_pinned": true,
    "pinned_at": "2026-11-13T10:10:00+00:00",
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:10:00+00:00"
  },
  "message": "تم تثبيت المحادثة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

### Unpin conversation

`DELETE /api/v1/conversations/{conversation}/pin` (authenticated, owner)

Removes the pin from the conversation. Unpinning a conversation that is not pinned is a no-op.

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6A",
    "title": "Planning a trip",
    "is_pinned": false,
    "pinned_at": null,
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:10:00+00:00"
  },
  "message": "تم إلغاء تثبيت المحادثة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

## Messages

Message fields:

| Field | Type | Nullable | Description |
|---|---|---:|---|
| `id` | string (ULID) | no | Message identifier. |
| `role` | string enum | no | `user`, `assistant`, or `system`. |
| `content` | string | no | Message text. |
| `status` | string enum | no | `pending`, `complete`, `failed`, or `cancelled`. |
| `created_at` | string (ISO 8601) | yes | Creation timestamp. |
| `updated_at` | string (ISO 8601) | yes | Last update timestamp. |

All message endpoints require authentication and ownership of the parent conversation. Nested message routes are scoped to the parent conversation; a message ID belonging to another conversation returns `404 not_found`.

Message operations share a per-conversation operation lock. While another send, continuation, edit, regeneration, or deletion is running on the same conversation, the API returns `409 conversation_busy`.

### List messages

`GET /api/v1/conversations/{conversation}/messages` (authenticated, owner)

Lists messages for a conversation using cursor pagination. The newest page is returned first, but each page is ordered chronologically. All message statuses are returned.

Path parameters:

| Parameter | Type | Description |
|---|---|---|
| `conversation` | string (ULID) | Conversation identifier. |

Query parameters:

| Parameter | Type | Required | Rules |
|---|---|---:|---|
| `limit` | integer | no | 1-100. Defaults to 25. |
| `cursor` | string | no | Cursor returned in `meta.next_cursor` or `meta.prev_cursor`. |

Example request:

```http
GET /api/v1/conversations/01JZ9M0R6V8X3K4P5Q7S9T2W6A/messages?limit=25
```

Success: `200 OK` with cursor pagination (`data`, `links`, `meta`).

```json
{
  "data": [
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6B",
      "role": "user",
      "content": "What should I pack?",
      "status": "complete",
      "created_at": "2026-11-13T10:14:00+00:00",
      "updated_at": "2026-11-13T10:14:00+00:00"
    },
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6C",
      "role": "assistant",
      "content": "Pack layers, comfortable shoes, and a rain jacket.",
      "status": "complete",
      "created_at": "2026-11-13T10:14:05+00:00",
      "updated_at": "2026-11-13T10:14:05+00:00"
    }
  ],
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": null
  },
  "meta": {
    "path": "https://example.test/api/v1/conversations/01JZ9M0R6V8X3K4P5Q7S9T2W6A/messages",
    "per_page": 25,
    "next_cursor": null,
    "prev_cursor": null
  }
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Send message

`POST /api/v1/conversations/{conversation}/messages` (authenticated, owner)

Persists a complete user message and generates a complete assistant reply. If the conversation title is blank, the first user message titles it using the first 60 characters of the message. If the AI provider fails, the user message remains persisted, no assistant message is created, and the API returns `502 provider_failed`.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `content` | string | yes | 1-10,000 characters. |
| `persona_id` | string (ULID) | no | Nullable. Must be a visible persona for the authenticated user. Inactive or foreign custom personas are rejected. |

Example request:

```json
{
  "content": "Hello there",
  "persona_id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D"
}
```

Success: `201 Created`

```json
{
  "data": {
    "user_message": {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6B",
      "role": "user",
      "content": "Hello there",
      "status": "complete",
      "created_at": "2026-11-13T10:14:00+00:00",
      "updated_at": "2026-11-13T10:14:00+00:00"
    },
    "assistant_message": {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6C",
      "role": "assistant",
      "content": "Hello, how can I help?",
      "status": "complete",
      "created_at": "2026-11-13T10:14:05+00:00",
      "updated_at": "2026-11-13T10:14:05+00:00"
    }
  },
  "message": "تم إرسال الرسالة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conversation_busy`, `422 validation_failed`, `429 rate_limited`, `500 server_error`, `502 provider_failed`.

### Continue conversation

`POST /api/v1/conversations/{conversation}/messages/continue` (authenticated, owner)

Generates a new assistant reply from completed message history without adding a new user message. The request body is optional.

Continuation is rejected with `422 validation_failed` when there are no completed messages or when the latest message is still `pending`.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `persona_id` | string (ULID) | no | Nullable. Must be a visible persona for the authenticated user. |

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6C",
    "role": "assistant",
    "content": "Continued answer",
    "status": "complete",
    "created_at": "2026-11-13T10:15:00+00:00",
    "updated_at": "2026-11-13T10:15:00+00:00"
  },
  "message": "تم متابعة المحادثة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conversation_busy`, `422 validation_failed`, `429 rate_limited`, `500 server_error`, `502 provider_failed`.

### Edit message

`PATCH /api/v1/conversations/{conversation}/messages/{message}` or `PUT /api/v1/conversations/{conversation}/messages/{message}` (authenticated, owner)

Edits a user message and regenerates its immediate assistant reply. If the edited user message is the latest message and has no assistant reply, an assistant message is created. Later messages are preserved.

Only `user` messages can be edited. Editing an assistant message returns `403 forbidden`. If the message immediately following the target is not an assistant message, the edit is rejected with `422 validation_failed`. If the AI provider fails, the user edit and assistant replacement are rolled back.

Path parameters:

| Parameter | Type | Description |
|---|---|---|
| `conversation` | string (ULID) | Conversation identifier. |
| `message` | string (ULID) | Message identifier within the conversation. |

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `content` | string | yes | 1-10,000 characters. |
| `persona_id` | string (ULID) | no | Nullable. Must be a visible persona for the authenticated user. |

Example request:

```json
{
  "content": "Edited question"
}
```

Success: `200 OK`

```json
{
  "data": {
    "user_message": {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6B",
      "role": "user",
      "content": "Edited question",
      "status": "complete",
      "created_at": "2026-11-13T10:14:00+00:00",
      "updated_at": "2026-11-13T10:16:00+00:00"
    },
    "assistant_message": {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6C",
      "role": "assistant",
      "content": "Edited answer",
      "status": "complete",
      "created_at": "2026-11-13T10:14:05+00:00",
      "updated_at": "2026-11-13T10:16:00+00:00"
    }
  },
  "message": "تم تعديل الرسالة بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conversation_busy`, `422 validation_failed`, `429 rate_limited`, `500 server_error`, `502 provider_failed`.

### Regenerate response

`POST /api/v1/conversations/{conversation}/messages/{message}/regenerate` (authenticated, owner)

Regenerates an assistant response. The target message may be either:

- An `assistant` message: the message is replaced in place using completed history before it. At least one completed user message must exist in that history.
- A completed `user` message: the assistant reply immediately following it is replaced, or a new assistant reply is created when the user message is the latest message. The next message, when present, must be an assistant message.

A user target must have `status = complete`. A `system` message cannot be regenerated. Provider failure leaves every message unchanged.

Path parameters:

| Parameter | Type | Description |
|---|---|---|
| `conversation` | string (ULID) | Conversation identifier. |
| `message` | string (ULID) | Message identifier within the conversation. |

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `persona_id` | string (ULID) | no | Nullable. Must be a visible persona for the authenticated user. |

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6C",
    "role": "assistant",
    "content": "Fresh answer",
    "status": "complete",
    "created_at": "2026-11-13T10:14:05+00:00",
    "updated_at": "2026-11-13T10:17:00+00:00"
  },
  "message": "تم إعادة توليد الرد بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conversation_busy`, `422 validation_failed`, `429 rate_limited`, `500 server_error`, `502 provider_failed`.

## AI Personas

Persona fields:

| Field | Type | Nullable | Description |
|---|---|---:|---|
| `id` | string (ULID) | no | Persona identifier. |
| `name` | string | no | Persona name, maximum 100 characters by default. |
| `system_prompt` | string | no | System prompt, maximum 8,000 characters by default. |
| `is_active` | boolean | no | Whether the persona can be selected. |
| `is_system` | boolean | no | `true` for the system default persona, `false` for custom personas. |
| `created_at` | string (ISO 8601) | yes | Creation timestamp. |
| `updated_at` | string (ISO 8601) | yes | Last update timestamp. |

Behavior:

- The system default persona is provisioned automatically and is always active.
- The system default persona is visible to every authenticated user but cannot be updated, deleted, activated, or deactivated.
- Custom personas are visible only to their owner.
- Custom persona creation is limited by `config('ai.personas.max_custom')`, which defaults to 3.
- An authenticated user may create personas (`create` policy always allows it); update, delete, activate, and deactivate require ownership and a non-system persona.

### List personas

`GET /api/v1/personas` (authenticated)

Returns the system default persona followed by the authenticated user's custom personas. Results are ordered with the system default first, then custom personas by name and ID. This endpoint is not paginated.

Success: `200 OK`

```json
{
  "data": [
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W60",
      "name": "Default Assistant",
      "system_prompt": "You are a helpful AI assistant. Answer clearly and concisely, and match the user language.",
      "is_active": true,
      "is_system": true,
      "created_at": "2026-11-01T00:00:00+00:00",
      "updated_at": "2026-11-01T00:00:00+00:00"
    },
    {
      "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D",
      "name": "Code Reviewer",
      "system_prompt": "Review code carefully and explain tradeoffs.",
      "is_active": true,
      "is_system": false,
      "created_at": "2026-11-10T09:00:00+00:00",
      "updated_at": "2026-11-10T09:00:00+00:00"
    }
  ]
}
```

Errors: `401 unauthenticated`, `429 rate_limited`, `500 server_error`.

### Create persona

`POST /api/v1/personas` (authenticated)

Creates a custom persona owned by the authenticated user. `name` is trimmed and whitespace-squished. `system_prompt` is trimmed and normalized to `\n` line endings.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `name` | string | yes | Maximum 100 characters by default. Cannot be blank. |
| `system_prompt` | string | yes | Maximum 8,000 characters by default. Cannot be blank. |
| `is_active` | boolean | no | Defaults to `true`. |

Example request:

```json
{
  "name": "Code Reviewer",
  "system_prompt": "Review code carefully.\nExplain tradeoffs.",
  "is_active": true
}
```

Success: `201 Created`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D",
    "name": "Code Reviewer",
    "system_prompt": "Review code carefully.\nExplain tradeoffs.",
    "is_active": true,
    "is_system": false,
    "created_at": "2026-11-13T10:20:00+00:00",
    "updated_at": "2026-11-13T10:20:00+00:00"
  },
  "message": "تم إنشاء الشخصية بنجاح."
}
```

Errors: `401 unauthenticated`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

When the custom persona limit is exceeded, the response is `422 validation_failed` with an error on `name`.

### Get persona

`GET /api/v1/personas/{persona}` (authenticated, owner or system default)

Returns a visible persona. The system default persona is visible to every authenticated user.

Path parameters:

| Parameter | Type | Description |
|---|---|---|
| `persona` | string (ULID) | Persona identifier. |

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D",
    "name": "Code Reviewer",
    "system_prompt": "Review code carefully and explain tradeoffs.",
    "is_active": true,
    "is_system": false,
    "created_at": "2026-11-13T10:20:00+00:00",
    "updated_at": "2026-11-13T10:20:00+00:00"
  }
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

### Update persona

`PATCH /api/v1/personas/{persona}` or `PUT /api/v1/personas/{persona}` (authenticated, owner)

Updates an owned custom persona. The system default persona cannot be updated. All body fields are optional, but when present they are validated.

Request body:

| Field | Type | Required | Rules |
|---|---|---:|---|
| `name` | string | no | When present, required, maximum 100 characters by default, cannot be blank. |
| `system_prompt` | string | no | When present, required, maximum 8,000 characters by default, cannot be blank. |
| `is_active` | boolean | no | When present, sets the activation state. |

Example request:

```json
{
  "name": "Updated Persona",
  "system_prompt": "Updated instructions.",
  "is_active": false
}
```

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D",
    "name": "Updated Persona",
    "system_prompt": "Updated instructions.",
    "is_active": false,
    "is_system": false,
    "created_at": "2026-11-13T10:20:00+00:00",
    "updated_at": "2026-11-13T10:25:00+00:00"
  },
  "message": "تم تحديث الشخصية بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `422 validation_failed`, `429 rate_limited`, `500 server_error`.

### Delete persona

`DELETE /api/v1/personas/{persona}` (authenticated, owner)

Deletes an owned custom persona. The system default persona cannot be deleted.

Success: `200 OK`

```json
{
  "data": null,
  "message": "تم حذف الشخصية بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

### Activate persona

`POST /api/v1/personas/{persona}/activate` (authenticated, owner)

Activates an owned custom persona. Activating an already active persona is a no-op. The system default persona cannot be activated through this endpoint.

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D",
    "name": "Code Reviewer",
    "system_prompt": "Review code carefully and explain tradeoffs.",
    "is_active": true,
    "is_system": false,
    "created_at": "2026-11-13T10:20:00+00:00",
    "updated_at": "2026-11-13T10:26:00+00:00"
  },
  "message": "تم تفعيل الشخصية بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

### Deactivate persona

`POST /api/v1/personas/{persona}/deactivate` (authenticated, owner)

Deactivates an owned custom persona. Deactivating an already inactive persona is a no-op. The system default persona cannot be deactivated.

Success: `200 OK`

```json
{
  "data": {
    "id": "01JZ9M0R6V8X3K4P5Q7S9T2W6D",
    "name": "Code Reviewer",
    "system_prompt": "Review code carefully and explain tradeoffs.",
    "is_active": false,
    "is_system": false,
    "created_at": "2026-11-13T10:20:00+00:00",
    "updated_at": "2026-11-13T10:27:00+00:00"
  },
  "message": "تم تعطيل الشخصية بنجاح."
}
```

Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `429 rate_limited`, `500 server_error`.

### Persona selection for messages

Message send, edit, continuation, and regeneration accept an optional `persona_id`:

- When `persona_id` is supplied, that persona must be a valid ULID, visible to the authenticated user, and active for custom personas.
- When `persona_id` is omitted or `null`, the most recently updated active custom persona is used, falling back to the system default persona.
- An invalid, inactive, or foreign persona returns `422 validation_failed` on `persona_id` before any AI request is created.

## Authorization and ownership

- Authentication is handled by Sanctum. Authorization is enforced separately with policies.
- `ConversationPolicy` requires ownership for `view`, `update`, `delete`, and `sendMessage`.
- `AiPersonaPolicy` allows all authenticated users to create custom personas and to view the system default persona. Custom persona `update`, `delete`, `activate`, and `deactivate` require ownership. The system default persona is immutable.
- Policy denials return `403 forbidden`.
- Nested message routes use scoped bindings: a message from another conversation resolves as `404 not_found` before controller authorization.
- Bulk conversation deletion is all-or-nothing. Any foreign or missing ID produces `403 forbidden` and no deletions occur.

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
- AI provider errors return a generic `502 provider_failed` response without exposing provider details.
