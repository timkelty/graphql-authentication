# GraphQL Authentication Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 1.1.6 - 2020-11-11

### Fixed

- Fixed issue with `updatePassword` mutation failing validation
- Fixed issue with custom fields on users not setting correct values on `register` and `updateUser` mutations

## 1.1.5 - 2020-11-10

### Fixed

- Fixed issue with project config sync throwing `Calling unknown method: craft\console\Request::getBodyParam()`

## 1.1.4 - 2020-11-09

### Improved

- Improved `isGraphiqlRequest` detection

## 1.1.3 - 2020-11-09

### Fixed

- Fixed issues with non-user tokens throwing `Invalid Authorization Header`. Previously it was _always_ trying to validate queries against user permissions, but this was causing conflicts with tokens that will only be used server-side (i.e. in Next.js SSG requests)

## 1.1.2 - 2020-11-09

### Fixed

- Added empty fallback to `Craft::$app->getRequest()->getReferrer()`, to fix error if referrer is blank

## 1.1.1 - 2020-11-09

### Fixed

- Fixed issue with `isGraphiqlRequest` always returning `true`, breaking Craft's GraphiQL explorer

## 1.1.0 - 2020-11-04

### Added

- Added support for HTTP-Only cookie tokens, improving security (thanks [@timkelty](https://github.com/timkelty))

## 1.0.1 - 2020-11-03

### Added

- Update `lastLoginDate` on users when running `authenticate`/`register` mutations

## 1.0.0 - 2020-11-03

### Added

- Initial release
