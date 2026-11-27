# Changelog

## 2.1.0 — 2026-11-27

- CRUD view responses now include an `action` array with the visible actions
  authorized for the displayed object. The array is empty when none are available.
- If your controller overrides `AbstractCrudController::getActions()`, add the
  optional third parameter `?object $object = null` to its signature and pass
  the object through when delegating to the parent.
