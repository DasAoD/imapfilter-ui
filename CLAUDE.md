# imapfilter-ui – Projektkontext für Claude Code

- Der Kern ist die Lua-Code-Generierung aus JSON-Regeln: bei Änderungen an der Regel-Logik sicherstellen, dass das generierte Lua gültig bleibt und von imapfilter fehlerfrei geparst wird.
- Mehrbenutzerbetrieb: Änderungen an Regeln oder Ordnern eines Users dürfen nie andere Benutzerkonten beeinflussen (striktes Scoping pro User-ID).
