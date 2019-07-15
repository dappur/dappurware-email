# Changelog

## [Unreleased]
### No Changes

## [4.0.5] - 2019-07-14
### Fixed
- Class Error
- Code Cleanup

## [4.0.4] - 2019-07-12
### Changed
- Code Cleanup

## [4.0.3] - 2018-12-15
### Added
- Added seperate status table for mailgun webhook support

## [4.0.2] - 2018-12-13
### Added
- [soundasleep/html2text](https://github.com/soundasleep/html2text) was added as a dependency

## [4.0.1] - 2018-12-13
### Changed
- Removed plain text email generation. This is now done automatically via [soundasleep/html2text](https://github.com/soundasleep/html2text);
- Cleaned up code in general
- Simplified processAndSend Function

### Added
- Errors are now tracked in the database if email logging is enabled in the framework.
- Status field with a default of `sent` is now tracked with emails.  This was added for eventual webhook support through mailgun/other providers.

## [4.0.0] - 2018-12-05
### Notes
I am seperating the larger dapurware classes into their own packages as I would liek to develop them out a little more.

[Unreleased]: https://github.com/dappur/dappurware-email/compare/v4.0.5...HEAD
[4.0.5]: https://github.com/dappur/dappurware-email/compare/v4.0.4...v4.0.5
[4.0.4]: https://github.com/dappur/dappurware-email/compare/v4.0.3...v4.0.4
[4.0.3]: https://github.com/dappur/dappurware-email/compare/v4.0.2...v4.0.3
[4.0.2]: https://github.com/dappur/dappurware-email/compare/v4.0.1...v4.0.2
[4.0.1]: https://github.com/dappur/dappurware-email/compare/v4.0.0...v4.0.1
[4.0.0]: https://github.com/dappur/dappurware-email/releases/tag/v4.0.0