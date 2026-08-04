# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Configurable request and connection timeouts.
- Structured API error metadata for status-aware handling.

### Changed

- PHP 8.3 and Laravel 12 are now the minimum supported versions.
- Message history requests now require an explicit date range.
- Bot and connection link resolution no longer repeat equivalent searches.
- Webhook payload validation now uses Laravel validation while preserving the existing response contract.

### Fixed

- Preserved local email values during partial connection updates.
- Prevented reserved fields from overriding message recipients.
- Prevented lazy-loading violations during connection creation.
- Reconciled local state when remote connection deletion succeeds but local deletion fails.
- Hardened handling of asymmetric user list responses and invalid HTTP client configuration.
- Clarified optional connection link fields in the documentation.

## [1.0.1] - 2026-08-03

### Fixed

- Improved webhook response diagnostics.
- Allowed webhook payloads without `event_id` while preserving event validation.

## [1.0.0] - 2026-08-03

### Added

- Initial public release of the Telegga Laravel SDK.

[Unreleased]: https://github.com/VladPimk/telegga-laravel-sdk/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/VladPimk/telegga-laravel-sdk/compare/v1.0...v1.0.1
[1.0.0]: https://github.com/VladPimk/telegga-laravel-sdk/releases/tag/v1.0
