# Fixture "theme"

`stubThemeDirs()` points `get_template_directory()` here, so anything resolving paths off the
template directory finds a realistic layout instead of a real site.

`src/manifests/` carries the same three artifacts a built theme would, which is what lets
`SchemaOracle` resolve a schema in the unit suite — and therefore what lets the generation
pipeline's retry loop fire on a real schema violation rather than never firing at all.
