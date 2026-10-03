# MotionBase

Autorenumgebung für Kurse: Themen → Kapitel → Seiten, dazu interaktive Grafiken, Wissenschecks und ein
KI-Assistent. Laravel 12, Inertia, React 19, Editor.js.

## Produktprinzip: MotionBase ergänzt Moodle

**Moodle ist der Ort, an dem die Klasse arbeitet. MotionBase ist der Ort, an dem Inhalte entstehen.**

Lehrpersonen an der Schule führen ihren Unterricht in Moodle: Kurs, Abschnitte, Aufgaben, Abgaben,
Bewertungen, Termine. MotionBase ersetzt davon nichts. Es liefert die Inhalte dafür und ist nur dann ein
Gewinn, wenn sie in Moodle ankommen, ohne dass jemand etwas abtippt, doppelt pflegt oder die Klasse den Kurs
verlassen muss.

Deshalb gilt für **jede** Änderung an Inhaltsarten, Struktur oder Autorenoberfläche: Zuerst beantworten, was
in Moodle daraus wird. Gibt es darauf keine Antwort, ist die Funktion nicht fertig gedacht.

## User Stories

### 1. Einmal schreiben, in Moodle unterrichten

> Als Lehrperson möchte ich Theorie und Aufgaben einmal in MotionBase schreiben und in meinem Moodle-Kurs als
> gewohnte Moodle-Aktivitäten einsetzen, damit ich nichts doppelt pflege und meine Klasse Moodle nicht
> verlassen muss.

- Jede Inhaltsart hat genau eine Entsprechung in Moodle (Tabelle unten). Nichts landet als blosser Link.
- Was in MotionBase geändert wird, steht beim nächsten Öffnen in Moodle. In Moodle muss nie nachgezogen
  werden.
- Abgabe, Termine und Bewertung gehören Moodle. **MotionBase bewertet nie und führt keine Prüfungen.**
- Was eine Lehrperson in Moodle ergänzt, bleibt beim nächsten Abgleich bestehen.

### 2. Seite oder Aufgabe ist beim Schreiben sichtbar

> Als Autorin möchte ich schon beim Anlegen festlegen und in der Liste sehen, ob eine Seite Theorie oder eine
> Aufgabe ist, damit ich weiss, was in Moodle daraus wird, und die passenden Felder bekomme.

- Der Typ wird beim Anlegen gewählt, nicht nachträglich in einem Einstellungsfeld gesucht.
- Die Seitenliste zeigt den Typ, sortiert aber nicht um: Die Reihenfolge im Kapitel bestimmt die Autorin.
- Eine Aufgabe zeigt die Felder, die eine Aufgabe braucht (Abgabeart), eine Theorieseite nicht.
- Der Typ ist am Inhalt erkennbar, nicht nur in Moodle: Auch die öffentliche Ansicht in MotionBase macht
  sichtbar, dass eine Seite eine Aufgabe ist.

### 3. Vorher sehen, was entsteht

> Als Lehrperson möchte ich vor dem Übernehmen sehen, welche Moodle-Aktivitäten aus einem Kapitel entstehen,
> damit ich keine Überraschungen im Kurs habe.

- In der Auswahl steht bei jeder Zeile, was daraus wird (Buch, Textseite, Aufgabe mit Abgabeart).
- Was schon im Kurs ist, ist als solches markiert.
- Mehreres auf einmal übernehmen ist der Normalfall, nicht die Ausnahme.

### 4. Ein zweites Mal übernehmen macht kein Durcheinander

> Als Lehrperson möchte ich dieselbe Lektion gefahrlos nochmals hinzufügen oder ein Kapitel erweitern können,
> ohne Duplikate und ohne verlorene Abgaben.

- Jede erzeugte Aktivität trägt ihre Herkunft in der ID-Nummer (`motionbase-chapter-39`), auch nach Sicherung
  und Wiederherstellung.
- Neue Lektionen kommen ins bestehende Buch, gelöschte werden ausgeblendet statt entfernt.
- Abgaben und Bewertungen in Moodle überlebt jede Aktualisierung.

## Zuordnung MotionBase → Moodle

| MotionBase | Moodle | Wie |
|---|---|---|
| Kapitel | **Buch**, ein Buchkapitel pro Theorieseite | Inhalt live, Struktur beim Öffnen abgeglichen |
| Ganzer Kurs | ein **Buch pro Kapitel** | |
| Einzelne Seite | **Textseite** | Inhalt live |
| Aufgabe | **Aufgabe** mit Abgabe (keine, Datei, Text, beides) | Inhalt live, Bewertung in Moodle |
| Interaktive Grafik | eingebettet in Buch/Textseite/Aufgabe | läuft weiter auf MotionBase, Höhe meldet sie selbst |
| KI-Assistent | Aktivität des externen Tools (LTI) | einziger Teil, der LTI braucht |
| Wissenscheck | **noch offen** | siehe unten |

Technisch: Das Moodle-Plugin liegt im Repo unter `moodle-plugin/motionbase` (`filter_motionbase`). Es holt
Inhalte über `/moodle/catalog`, `/moodle/lessons/{id}`, `/moodle/chapters/{id}` und `/moodle/tasks/{id}` und
weist sich mit dem LTI-Schlüssel des Moodle aus. Details: `moodle-plugin/motionbase/README.md`.

## Regeln für Änderungen

- **Neue Inhaltsart?** Erst die Moodle-Entsprechung festlegen, dann bauen. Notfalls bewusst entscheiden, dass
  es in Moodle nicht erscheint – und das in der Autorenoberfläche sagen.
- **Nichts kopieren, was live gehen kann.** Moodle speichert nur eine Notkopie; angezeigt wird der aktuelle
  Stand aus MotionBase.
- **Keine Bewertung, keine Prüfungen in MotionBase.** Wissenschecks sind Selbstkontrolle ohne Noten.
- **Keine Pflicht zu LTI.** Was ohne externes Tool als Moodle-Aktivität möglich ist, wird so gebaut.
- **Sprache für Lehrpersonen:** Deutsch, Schweizer Schreibweise (ss statt ß). Begriffe wie in Moodle:
  Aufgabe, Abgabe, Buch, Textseite, Abschnitt.

## Offene Punkte

- **Wissenscheck:** Quizblöcke erscheinen in Moodle noch gar nicht. Zu entscheiden: eingebettet aus
  MotionBase (Selbstkontrolle, keine Noten) oder als nativer Moodle-Test – Letzteres widerspricht «keine
  Prüfungen».
- **Backend trennt Seite und Aufgabe noch nicht sichtbar** (Story 2). Heute hängt es am Feld
  `sections.task_submission`; die Seitenliste zeigt den Unterschied nicht.
- **KI-Assistent** ist das einzige Stück, das noch über LTI läuft.

## Umgebung

- DDEV: `motionbase.ddev.site`, lokales Moodle mit Plugin unter `~/Projects/motionbase-moodle`
  (`motionbase-moodle.ddev.site`, README dort beschreibt Beispielkurs und Testlogins).
- Deployment: Hostpoint, `docs/go-live.md`.
