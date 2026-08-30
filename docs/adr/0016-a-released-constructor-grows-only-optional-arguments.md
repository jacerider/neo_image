# 0016 — A released constructor on the formatter base grows only optional arguments

**Status:** accepted · **Date:** 2026-08-29
**Context:** `neo_image` — the **formatter base** and the **optional argument** its constructor takes
**Issue:** jacerider/neo_image#14

**Decision.** A service argument added to `NeoImageBaseFormatter::__construct()` after the class has
been released is added last, defaulted to NULL, and resolved from the container by name inside the
constructor, behind an `E_USER_DEPRECATED` notice naming the major it becomes required in. The class
stays extensible and is not marked `@internal`; `create()` keeps passing the argument, so the
container path never reaches the fallback. Nothing else in the package is audited for the same shape
here.

**Why it needs recording.** A reader lands on a class with constructor injection making a `\Drupal::`
static call, and every standards axis in this pipeline flags that on sight. It is not injection
gone wrong: a formatter base is extended by convention, a subclass supplies its own `create()`, and
that factory calls `new static()` with the argument list it was written against. The count is fixed
at the subclass's compile time and cannot be corrected from here, so a required addition is an
`ArgumentCountError` on somebody else's site the moment a page renders — on roughly thirty sites,
none of which can be greped from any one of them. The fallback exists for exactly the caller the
grep cannot find. Core does the same thing in the same place: `ImageFormatter` in Drupal 11.4 takes
its new `ImageDerivativeUtilities` nullable-last, `@trigger_error`s, and resolves it from the
container.

**Rejected.**
- `final` on the base — does not compile: two formatters in this module extend it.
- `@internal` plus a release note — reassigns blame without removing the fatal, and contradicts the
  two decisions this package already took the other way (native Twig types declined, a minor
  accepted for a docblock deprecation) to protect callers nobody could enumerate.
- Both the shim and `@internal` — states two contracts and honours neither.
- A second constructor or a static factory for the old shape — new public surface on the class this
  decision exists to stop adding to.
- Fall back silently, with no deprecation — "will be required in the next major" then reaches no
  subclass author until the major arrives.

**Cost.** The constructor now has a branch that is dead on every supported path, and one static
service call inside a class that otherwise injects everything; both will be re-proposed for deletion
by the next scan that reads the class without this file, and the eight-argument form is supported
until `2.0.0`. `FormatterConstructorContractTest` pins both construction paths and the deprecation
message, so deleting the branch fails a test rather than a site.
