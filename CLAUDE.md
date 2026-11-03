# imapfilter-ui – Projektkontext für Claude Code

- Der Kern ist die Lua-Code-Generierung aus JSON-Regeln: bei Änderungen an der Regel-Logik sicherstellen, dass das generierte Lua gültig bleibt und von imapfilter fehlerfrei geparst wird.
- Mehrbenutzerbetrieb: Änderungen an Regeln oder Ordnern eines Users dürfen nie andere Benutzerkonten beeinflussen (striktes Scoping pro User-ID).
- Versionierung: SemVer in der Datei `VERSION` (ohne `v`), Tag-Format `vX.Y.Z`. Der Tag löst `.forgejo/workflows/deploy.yml` aus und rollt auf Prod aus. Prod steht auf dem Tag (detached HEAD), kein `git pull` mehr.
  - Der PR hebt `VERSION` selbst an, direkt nach dem Merge kommt der Tag. Jeder Code-Merge auf main bekommt einen Tag, damit main und Prod nicht auseinanderlaufen. Reine Doku-/CLAUDE.md-Änderungen: kein Bump, kein Tag.
  - Solange 0.x: Minor für neue, sichtbare Funktionen und für Änderungen am Format von `rules.json`; Patch für Fehlerkorrekturen und kleine Änderungen ohne neue Funktion. 1.0.0 legt Daniele fest.
