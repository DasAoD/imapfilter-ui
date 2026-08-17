# imapfilter-ui

> **📌 Mirror-Hinweis:** Dieses Repository ist ein automatischer Spiegel.
> Die primäre Entwicklung findet auf **[git.uliana.de/DasAoD/imapfilter-ui](https://git.uliana.de/DasAoD/imapfilter-ui)** statt.
> Issues und Pull Requests bitte dort öffnen.

Web-UI zur Verwaltung von [imapfilter](https://github.com/lefcha/imapfilter)-Regeln und IMAP-Ordnern.
Unterstützt mehrere Benutzer — jeder mit eigenem Mailkonto, eigenen Regeln und eigenem Lua-Setup.

---

## Features

- **Mehrbenutzerbetrieb** — jeder Benutzer verwaltet sein eigenes IMAP-Konto
- **Admin-Bereich** — Benutzer anlegen/löschen
- **Filterregeln** per Formular (kein manuelles Lua-Editieren)
- **Filterregeln automatisch generieren** — aus dem vorhandenen Inhalt der IMAP-Ordner vorgeschlagen, mit Vorschau vor dem Übernehmen
- **Sperrliste** mit optionalem Auto-Lernen aus dem Spam-Ordner
- **Lua-Generierung** aus JSON-Regeln
- **Dispatcher** — zentrales Scheduling für alle Benutzer
- **IMAP-Ordner** live anzeigen, anlegen, umbenennen und löschen

---

## Mitwirkende

Dieses Projekt wurde in Zusammenarbeit mit [Claude](https://claude.ai) (Sonnet 5) von [Anthropic](https://anthropic.com) entwickelt.  
Der überwiegende Teil des Codes, der Architektur und der Dokumentation wurde durch KI generiert und iterativ verfeinert.

| Rolle | Person / Tool |
|---|---|
| Projektidee & Anforderungen | [DasAoD](https://git.uliana.de/DasAoD) |
| Code, Architektur, Dokumentation | [Claude](https://git.uliana.de/Claude) (Anthropic) |

---

## License

[MIT](LICENSE)
