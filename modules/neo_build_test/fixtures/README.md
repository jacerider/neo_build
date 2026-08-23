# Fixture dist roots

Committed manifests for the scopes `NeoBuildLibraryRewriteTest` asserts against — no built
assets, and no theme: these directories carry no `.info.yml`, so theme discovery never sees a
`front` or `back` theme here and cannot collide with the site's real ones.

`%extension%` stands in for the fixture module's path from the docroot. The test substitutes it
when it copies these files to its temporary app root, because where Composer installs
`neo_build` is a per-site fact and a hard-coded prefix would make these fixtures silently miss.
