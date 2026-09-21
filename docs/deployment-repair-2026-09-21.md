# Repair after the platform import

The user explicitly requested committing and pushing all pending source changes, producing `d76e3efc`. That import is substantially broader than the WhatsApp isolation work; the earlier eight-path core inventory and production verdict do not describe the whole imported platform.

The failed deployment exposed these issues:

- A checked-in regular `build` file collided with the generated asset symlink. The placeholder is removed from source. A cross-platform helper creates the link, accepts an existing correct link, converts only the known placeholder, and refuses to overwrite other content. The package excludes the root link; actual assets remain in `public/build`, and existing server links are preserved.
- The architecture guard treated the explicitly requested platform import as new WhatsApp core edits. Its immutable platform baseline now records that exact commit, separately from the original import. Only files identical to that snapshot qualify; later changed/new core files, reverse dependencies and adapter bypasses still fail. This is an acknowledgment of the user's imported platform, not certification as an official vendor release.
- The imported route provider registered only updater routes. Normal host/admin/vendor/API routing is restored from the previously working revision.
- Approval forms, route-authoritative decisions, POST/CSRF, repeat protection and status events were overwritten by the import. Their previously tested generic implementations are reapplied without removing the new Service-related changes elsewhere in the controller.
- The MySQL registration fixture used SRID 4326 while the host explicitly uses `POINT_SRID`. The fixture schema and polygon now use the same host constant. Production spatial validation is unchanged.
- The registration source fixture records the imported Service email branches. Existing runtime registration tests still enforce translations, zones, subscriptions, preferences, Rental handling and module absence.
- Unix line-ending enforcement is restored for worker and release shell scripts.

CI remains required for deployment. No failure is converted to success, no tests are skipped, and no broad core-directory allowlist is introduced. Local build and build-link tests passed; final host/concierge and hosted workflow results must be checked for the released commit.
