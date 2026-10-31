# REST API v1 Specification & Contract Reference

## 1. Architectural Conventions

All external and desktop client integrations interact with the cloud backend through the versioned API router at `/api/v1/`.

### 1.1 Headers
| Header | Description | Required |
| :--- | :--- | :--- |
| `Authorization` | Bearer token format: `Bearer <access_token>` | Yes (for protected endpoints) |
| `Content-Type` | `application/json; charset=utf-8` | Yes (for POST / PUT / PATCH) |
| `X-Device-Id` | Unique client installation hardware/software identifier | Recommended |
| `X-Idempotency-Key`| UUIDv4 preventing duplicate execution on network retry | Recommended for state mutations |

### 1.2 Standard Response Envelopes

**Success Envelope:**
```json
{
  "success": true,
  "data": {},
  "meta": {
    "timestamp": "2026-10-31T14:30:00Z",
    "version": "1.0.0",
    "tenant_id": 1
  }
}
```

**Error Envelope:**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The provided customer email is invalid.",
    "details": []
  },
  "meta": {
    "timestamp": "2026-10-31T14:30:00Z"
  }
}
```

---

## 2. Authentication & Device Endpoints

### 2.1 `POST /api/v1/auth/login`
Authenticates a user and registers or binds a client device.

- **Request Body:**
  ```json
  {
    "email": "owner@ybt.com",
    "password": "password123",
    "device_id": "win-client-98f2b8e3-0d34-45aa-bb8e-123456789abc",
    "device_name": "Front Counter Windows POS",
    "platform": "windows_x64",
    "client_version": "1.0.0"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "access_token": "ybt_pat_...",
      "expires_in": 3600,
      "refresh_token": "ybt_ref_...",
      "user": {
        "id": 1,
        "name": "Vikram Malhotra",
        "email": "owner@ybt.com",
        "role": "owner"
      },
      "business": {
        "id": 1,
        "name": "Apex Infotech Solutions",
        "currency": "INR",
        "tax_type": "GST"
      }
    }
  }
  ```

### 2.2 `POST /api/v1/auth/refresh`
Exchanges a valid refresh token for a new access token (with refresh token rotation).

- **Request Body:**
  ```json
  {
    "refresh_token": "ybt_ref_..."
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "access_token": "ybt_pat_...",
      "expires_in": 3600,
      "refresh_token": "ybt_ref_new_..."
    }
  }
  ```

---

## 3. Core Business Endpoints

### 3.1 `GET /api/v1/customers`
Retrieves a paginated list of customers for the active tenant.
- **Query Parameters:** `page`, `per_page`, `search`, `status`
- **Response (200 OK):** Array of customer records scoped to `TenantContext::id()`.

### 3.2 `POST /api/v1/customers`
Creates a customer. Accepts client-generated `sync_uuid` to ensure offline idempotency.
- **Request Body:**
  ```json
  {
    "sync_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Arun Kumar",
    "phone": "+91 98765 43210",
    "email": "arun@example.com",
    "billing_city": "Mumbai"
  }
  ```

### 3.3 `POST /api/v1/invoices`
Generates a new tax invoice. If created offline, accepts a temporary client invoice number and returns the official assigned sequence.
- **Request Body:**
  ```json
  {
    "sync_uuid": "c3d6118b-592f-4886-bce7-71c8411b0257",
    "client_temp_number": "OFF-TMP-001",
    "customer_id": 5,
    "invoice_date": "2026-10-31",
    "items": [
      {
        "description": "Mobile Screen Replacement AMOLED",
        "quantity": 1,
        "unit_price": 2500.00,
        "tax_rate": 18
      }
    ]
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "data": {
      "invoice_id": 42,
      "sync_uuid": "c3d6118b-592f-4886-bce7-71c8411b0257",
      "canonical_invoice_number": "INV-0042",
      "client_temp_number": "OFF-TMP-001",
      "grand_total": 2950.00,
      "status": "sent"
    }
  }
  ```

---

## 4. Offline Synchronization Endpoints

### 4.1 `POST /api/v1/sync/push`
Batched push of offline-queued operations from the desktop client to the cloud.

- **Request Body:**
  ```json
  {
    "device_id": "win-client-98f2b8e3",
    "operations": [
      {
        "operation_uuid": "op-89f41e57-...",
        "entity_type": "customers",
        "entity_uuid": "550e8400-e29b-...",
        "operation_type": "insert",
        "client_timestamp": "2026-10-31T13:45:00Z",
        "payload": { "name": "Arun Kumar", "phone": "9876543210" }
      }
    ]
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "processed_count": 1,
      "results": [
        {
          "operation_uuid": "op-89f41e57-...",
          "status": "applied",
          "server_version": 158
        }
      ]
    }
  }
  ```

### 4.2 `GET /api/v1/sync/pull`
Retrieves incremental changes made in the cloud since the client's last known cursor.

- **Query Parameters:**
  - `since_version`: Version integer from the previous sync run.
  - `limit`: Maximum entities per batch (default 500).
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "current_cursor": 182,
      "has_more": false,
      "entities": {
        "customers": [],
        "products": [],
        "invoices": [],
        "payments": [],
        "tombstones": []
      }
    }
  }
  ```

---

## 5. Health & Diagnostics

### 5.1 `GET /api/v1/health`
Zero-authentication monitoring endpoint reporting system status, API version, and database connectivity.
