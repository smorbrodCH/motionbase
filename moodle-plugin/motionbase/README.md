# MotionBase für Moodle (filter_motionbase)

Ein Knopf für Lehrpersonen: **„Aus MotionBase hinzufügen“** – unten in der
Aktivitätsauswahl und im Kurs unter *Mehr*. Dort genügt **ein Haken pro
Kapitel**: Daraus entstehen Moodles eigene Aktivitäten, das Buch mit der
Theorie und je eine Aufgabe. Wer einzelne Seiten will, klappt *Einzeln wählen*
auf. Ein Kapitel, das schon im Kurs ist, kann man gefahrlos nochmals
ankreuzen: Angelegt wird nur, was fehlt.

| In MotionBase | In Moodle |
|---|---|
| Kapitel | **Buch** mit der Theorie, dazu je eine **Aufgabe** |
| Ganzer Kurs | ein Buch pro Kapitel |
| Lektion | **Textseite** |
| Als Aufgabe markierte Lektion | **Aufgabe**, Abgabe wie in MotionBase festgelegt (keine, Datei, Text, Datei oder Text) |
| KI-Assistent | Aktivität des externen Tools MotionBase |

## Immer aktuell

Bücher, Textseiten und Aufgaben zeigen den Inhalt so, wie er in dem Moment in
MotionBase steht: Das Plugin ist ein Textfilter und setzt ihn bei jedem Aufruf
ein. Ein Buch gleicht beim Öffnen seine Kapitel ab – neue Lektionen kommen
dazu, gelöschte werden ausgeblendet (höchstens alle 30 Sekunden, weil oft eine
ganze Klasse gleichzeitig öffnet).

- Was eine Lehrperson über oder unter dem MotionBase-Teil schreibt, bleibt.
- Ist MotionBase nicht erreichbar, erscheint der zuletzt geholte Stand.
- Interaktive Grafiken erscheinen eingebettet; sie laufen auf MotionBase.
- Name, Termine, Abgabe und Bewertung legt die Lehrperson in Moodle fest.
  MotionBase bewertet nichts und bekommt keine Namen oder E-Mail-Adressen.

Welche Aktivität woraus entstanden ist, steht in ihrer ID-Nummer
(`motionbase-chapter-39` usw.) – das übersteht Sicherung und Wiederherstellung.

## Voraussetzungen

1. MotionBase ist in Moodle als externes Tool (LTI 1.3) eingerichtet.
2. Dieses Moodle ist in MotionBase unter *Moodle & LTI* als Plattform
   eingetragen.

Das Plugin braucht keine eigenen Schlüssel: Es signiert seine Anfragen mit dem
LTI-Schlüssel dieses Moodle, und MotionBase prüft sie gegen den Schlüsselsatz,
den es für LTI ohnehin schon kennt.

## Installieren

Das ZIP aus MotionBase (*Moodle & LTI › Plugin herunterladen*) unter
*Website-Administration › Plugins › Plugin installieren* hochladen. Der Filter
wird dabei überall eingeschaltet, an erster Stelle. Die Adresse von MotionBase
ist schon eingetragen; ändern lässt sie sich unter *Website-Administration ›
Plugins › Filter › MotionBase*.

Die Aktivitätsauswahl hat unten Platz für ein Plugin. Das Plugin übernimmt
ihn nur, solange dort nichts oder Moodles Standard (der Marketplace-Link)
steht; einstellbar unter *Website-Administration › Kurse › Aktivitätsauswahl*.

Moodle 4.5 bis 5.2.
