# Changelog

All notable changes to the Swish Suite plugin are documented in this file.

## [1.0.0-beta.1] - 2025-04-23

### Added

- **Craft Commerce 5 gateway integration**: Full support for Swish as a payment gateway in Commerce 5, including:
  - Purchase flow with automatic transaction linking
  - Refund support (full and partial)
  - Callback → Commerce transaction synchronization
  - Return/cancel URL customization per order
  
- **Standalone payment checkout**: Public checkout pages with:
  - E-Commerce flow (push notification via phone number)
  - M-Commerce flow (QR code scan)
  - Real-time payment status polling
  - Countdown timer and completion messaging
  
- **Payment management in CP**: Full CRUD operations with:
  - Dashboard showing payment volumes and status breakdown
  - Payments list with advanced filtering (status, flow, date range, reference, payment ID)
  - Payment details view with QR code generation
  - Manual status refresh from Swish API
  
- **Refund management in CP**:
  - Create refunds from confirmed payments
  - Track refund status and amounts
  - Prevent over-refunding (validates available balance)
  
- **Security features**:
  - Constant-time callback validation with `hash_equals()`
  - Database-level transaction locking for concurrent callback handling
  - CSRF protection on all forms except webhook endpoint
  - PII redaction in logs (phone numbers masked)
  - Amount validation rejecting scientific notation and negatives
  
- **Configuration**:
  - CP settings panel with environment variable support
  - Per-gateway overrides in Commerce (merchant number, certificates, test mode)
  - Health check for callback URL reachability
  - Comprehensive diagnostic information in Welcome screen
  
- **Logging & observability**:
  - Asynchronous file logging with daily rotation
  - Structured JSON logging for debugging
  - Fallback to Craft's default logger for errors
  - Payment/refund response storage for auditing
  
- **Documentation**:
  - README with Commerce integration examples
  - Environment variable documentation
  - Payment flow diagrams
  - Refund rules and status explanation
  
- **Testing**:
  - Unit tests for gateway response handling
  - Payment form validation tests
  - Request/response tests for Commerce integration

### Changed

- Improved error messaging to distinguish API errors from validation errors
- Enhanced callback logging with timestamp and entity type
- Callback route now configurable via `SWISH_CALLBACK_URI` environment variable
- Payment records now link to Commerce transactions via `commerceTransactionHash`

### Technical

- Database indexes on `paymentId` (unique) and `commerceTransactionHash` for lookup performance
- Guzzle custom middleware for P12 certificate handling
- Event-driven architecture for extensibility (callbacks trigger events)
- Strong typing throughout (PHP 7.4+ types, readonly properties)
- Follows Craft CMS 5 and Commerce 5 patterns and conventions

### Known Limitations

- Swish phone number validation is Swedish-specific (`46XXXXXXXXX` format)
- Callback URL must be publicly reachable over HTTPS (localhost/ngrok for development)
- Payment waiting page polling interval is fixed at 2.5 seconds
- No built-in retry logic for failed refunds (manual retry via CP)

---

## Version Support

- **Craft CMS**: ^5.0
- **Craft Commerce**: ^5.0 (optional, required for gateway integration)
- **PHP**: 8.1+

---

## Breaking Changes

None for beta release (first release).

---

## Migration Notes

No prior versions exist. Fresh installation only.

### For first-time setup

1. Install plugin via Composer
2. Set environment variables in `.env` (see README)
3. Open CP → Swish Suite → Welcome to verify configuration
4. (Optional) Configure gateway in Commerce → Settings → Gateways

---

## Future Roadmap

- **v1.1**: Automatic retry queue for failed refunds
- **v1.2**: Multi-country support (Norway, Finland if Swish expands)
- **v1.3**: Webhook event subscriptions for custom integrations
- **v2.0**: Dashboard analytics and reporting

---

## Security Policy

Security vulnerabilities should be reported privately to support@99x.se and not disclosed publicly until a fix is available.

---

## License

MIT — See LICENSE file in plugin root.
