# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-08-11

### Added

- Configurable request and connection timeouts.
- Safe automatic retries for repeatable requests, with bounded `Retry-After` handling.
- Structured API error metadata for status-aware handling.
- Typed response DTOs and route-specific mappers for every JSON API response.
- Access to unknown response fields through each DTO's `raw()` method.
- Configurable application user model and table for optional local connection relationships.
- Configurable webhook prefix, middleware, rate limit, enable switch, and token rotation.
- Persistent webhook event deduplication and the `telegga:webhook-events:clear` retention command.
- Ten-minute caching of validated raw bot API responses.
- Explicit `BroadcastAudience` selection for broadcasts.
- GitHub Actions checks for formatting, static analysis, SQLite, and MySQL.

### Changed

- PHP 8.3 and Laravel 12 are now the minimum supported versions.
- Message history requests now require an explicit date range.
- Media uploads now accept binary contents and a filename instead of a project filesystem path.
- Bot and connection link resolution no longer repeat equivalent searches.
- Webhook payloads are accepted from JSON only and validated with Laravel validation.
- The documented `event_id` is now required for `user.linked` events.
- Public API response contracts now use typed DTOs and typed collections instead of unstructured objects.
- Group listing now supports cursor pagination through `GroupPageData`.
- Broadcasts now require an explicit all-users or group audience.
- Bulk group membership results now expose documented `not_found` external identifiers.
- User routes and message history now use local connection UUIDs directly as supported Telegga `external_id` values.
- Bulk group membership now validates the explicitly supplied local UUIDs in one database query and sends them in one `external_ids` API request.
- Bot usernames are normalized consistently for storage and comparison.
- Package services are resolved per dependency request instead of being retained as singletons.
- Local bot and connection models use soft deletes and framework-managed UUID generation.
- Facade method documentation now inherits from the public contract instead of duplicating it.

### Fixed

- Preserved local email values during partial connection updates.
- Prevented reserved fields from overriding message recipients.
- Prevented lazy-loading violations during connection creation.
- Reconciled local state when remote connection deletion succeeds but local deletion fails.
- Hardened handling of asymmetric user list responses and invalid HTTP client configuration.
- Clarified optional connection link fields in the documentation.
- Reconciled both local creation and connection state from an authenticated `user.linked` webhook.
- Returned actionable webhook error responses and logged failures without bearer tokens or sensitive SQL bindings.
- Retried recoverable webhook bot failures within a bounded six-hour window.
- Prevented duplicate webhook processing and concurrent reuse of an `event_id`.
- Prevented local bot creation races while preserving bot-name reuse after soft deletion.
- Prevented stale bot cache entries after local bot changes.
- Merged nested package configuration recursively so partial application overrides remain safe.
- Preserved documented optional group-member failures when the API omits `not_found`.
- Removed redundant guards and unreachable Eloquent result branches.
- Corrected internal PHP argument usage and expanded static analysis coverage.

## [1.0.1] - 2026-08-03

### Fixed

- Improved webhook response diagnostics.
- Allowed webhook payloads without `event_id` while preserving event validation.

## [1.0.0] - 2026-08-03

### Added

- Initial public release of the Telegga Laravel SDK.

[Unreleased]: https://github.com/VladPimk/telegga-laravel-sdk/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/VladPimk/telegga-laravel-sdk/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/VladPimk/telegga-laravel-sdk/compare/v1.0...v1.0.1
[1.0.0]: https://github.com/VladPimk/telegga-laravel-sdk/releases/tag/v1.0
