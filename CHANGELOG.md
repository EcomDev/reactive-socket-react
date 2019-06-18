# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Fix
- Reduce CPU usage for workers by moving from future tick into interval timer 

## [1.0.0]
### Added
- `ReactEventEmmitter` and `ReactEventEmitterBuilder` binding for ReactPHP event loop
- Idle worker threshold (I/O inactivity ms) to allow better control of idle workers

[Unreleased]: https://github.com/ecomdev/reactive-socket-react/compare/1.0.0...HEAD
[1.0.0]: https://github.com/ecomdev/reactive-socket-react/compare/4b825dc642cb6eb9a060e54bf8d69288fbee4904...1.0.0
