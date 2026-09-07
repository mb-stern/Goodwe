# Modul für GoodWe für IP-Symcon

Dieses Repository stellt Module zur Einbindung von **GoodWe-Wechselrichtern, Batterien und HCA-Wallboxen** in IP-Symcon bereit.

Folgende Instanzen sind enthalten:

* **[GoodWe Inverter](GoodWeInverter)**
  Abfrage und Steuerung eines kompatiblen GoodWe-Wechselrichters über Modbus.
  Eine am Wechselrichter angeschlossene Batterie wird ebenfalls über diese Instanz eingebunden.

* **[GoodWe HCA2](GoodWeHCA2)**
  Abfrage und Steuerung einer kompatiblen GoodWe HCA G2 Wallbox direkt über Modbus.

## Unterstützte Komponenten

### GoodWe Wechselrichter

Getestet mit einem **GoodWe ET Plus+ 10 kW**.

Andere GoodWe-Wechselrichter, insbesondere Geräte der Serien **ET, EH, BH und BT**, dürften ebenfalls kompatibel sein, sofern sie dieselben Modbus-Register unterstützen.

Weitere Informationen:

**[Dokumentation GoodWe Inverter](GoodWeInverter)**

### GoodWe Batterie

Getestet mit einer **GoodWe Lynx Home F Plus**.

Andere mit dem verwendeten Wechselrichter kompatible Batterien dürften ebenfalls funktionieren, da die Batteriedaten über den Wechselrichter abgefragt werden.

Die Batterie ist Bestandteil der **GoodWe-Inverter-Instanz** und benötigt keine eigene Instanz.

Weitere Informationen:

**[Dokumentation GoodWe Inverter](GoodWeInverter)**

### GoodWe Wallbox

Unterstützt wird die **GoodWe HCA G2 Serie**.

Getestet mit einer **GW11K-HCA-20**.

Aktuell sind folgende Modelle im Modul vorgesehen:

* GW7K-HCA-20
* GW11K-HCA-20
* GW22K-HCA-20

Die Wallbox wird über eine eigene IP-Symcon-Instanz direkt per Modbus eingebunden.

Weitere Informationen:

**[Dokumentation GoodWe HCA2](GoodWeHCA2)**

## Voraussetzungen

* IP-Symcon ab Version 8.1
* GoodWe-Wechselrichter bzw. GoodWe-HCA-Wallbox mit erreichbarer Modbus-Schnittstelle

## Installation

Das Modul kann über den IP-Symcon Modul-Store installiert werden.

Nach der Installation stehen abhängig von der gewünschten Komponente folgende Instanzen zur Verfügung:

* **GoodWe Inverter**
* **GoodWe HCA2**

Die weitere Einrichtung ist in der jeweiligen Instanz-Dokumentation beschrieben.

## Versionen

### Version 2.15 (07.09.2026)

* Testweise Integration der Wallbox GoodWe HCA G2.

### Version 2.14 (21.07.2026)

* Mehr Parameter können abgefragt werden.
* Schnellere Abfrage durch blockweises Lesen der Register.
* Anpassungen der Variablenberechnungen.
* Warnmeldungen vom BMS können in Textform angezeigt werden.

### Version 2.13 (13.05.2026)

* Codeanpassung, um Fehlermeldungen beim Update oder Neuladen des Moduls zu verhindern.

### Version 2.12 (19.04.2026)

* Isolationswiderstand wurde um den Faktor 10 zu hoch berechnet.
* Erweiterung der Istwerte für Batterie 2 um Temperatur sowie maximale Lade- und Entlade-Ströme/Spannungen.
* Erweiterung der Werteberechnung um maximale Lade- und Entladeleistung für Batterie 2.
* Erweiterung der Strings von 4 auf 6 Stück.
* Unterstützung der bisherigen GoodWe-Wallbox entfernt.

### Version 2.11 (09.04.2026)

* Codeanpassung, da das Einfügen von zusätzlichen Registern fehlerhafte Variablen anzeigte.
* Erweiterung der Positionen im Objektbaum. Bei Bedarf ist eine Neuanordnung erforderlich.
* Anzeigemöglichkeit einer zweiten Batterie hinzugefügt.

### Version 2.10 (02.01.2026)

* Umstellung auf IPSModuleStrict.
* Mindestversion auf IP-Symcon 8.1 angehoben.

### Version 2.9 (16.12.2025)

* Die Wallbox-Steuerung wurde weiter überarbeitet.

### Version 2.8 (29.11.2025)

* Die Wallbox-Steuerung wurde überarbeitet.
* Debug-Ausgaben überarbeitet.

### Version 2.7 (15.09.2025)

* Das Konfigurationsformular wurde überarbeitet. Alle gewünschten Register können nun gleichzeitig über Checkboxen ausgewählt werden.
* Variablen werden nur noch aktualisiert, wenn sich der Wert ändert.

**Achtung:** Ein Downgrade auf eine Vorgängerversion kann zu fehlerhaftem Verhalten führen.

### Version 2.6 (10.08.2025)

* Die Ladeeinstellungen der Wallbox werden nicht mehr gepuffert, sondern direkt an die API gesendet.
* Maximale Leistung der damaligen GoodWe-Wallbox-Anbindung angepasst.
* Anpassungen der Aktualisierungsintervalle.

### Version 2.5 (01.07.2025)

* Bis zu 4 Strings und 8 MPP-Tracker verfügbar.
* Isolationswiderstand hinzugefügt.

### Version 2.4 (29.06.2025)

* Konfigurierbarer Offset-Wert für die damalige Wallbox-Sollleistung hinzugefügt.

### Version 2.3 (06.05.2025)

* Problem mit der Ansteuerung durch den Energiemanager behoben.
* Doppeltes Setzen des Timers nach einem Modulupdate behoben.
* Änderungen an der damaligen Wallbox-Steuerung.

### Version 2.2 (29.04.2025)

* Maximal freigegebene Lade- und Entladeleistung des Speichers kann als berechneter Wert ausgegeben werden.
* Fehler behoben, durch den neue Register nach einem Modulupdate nicht zur Auswahl standen.

### Version 2.1 (25.03.2025)

* Wallbox Soll- und Ist-Leistung werden in Watt statt kW angezeigt.

### Version 2.0 (15.02.2025)

* Neues Variablenprofil für EMSPowerSet.
* EMSPowerMode erweitert.
* Anpassungen für die Modul-Store-Kompatibilität.
* Dokumentation angepasst.
* Interne Anpassungen.

### Version 1.3 (25.01.2025)

* Fehlerhafte Konfiguration der Register 35105 und 35109 korrigiert.

### Version 1.2 (19.01.2025)

* Eigenes Variablenprofil für Prozentwerte.
* Interne Umbenennung einiger Funktionen und Timer.
* Dokumentation angepasst.

### Version 1.1 (14.01.2025)

* Steuerung von EMS Power Mode hinzugefügt.
* Steuerung von SOC online/offline hinzugefügt.
* Fehler im Register-Auswahlmenü behoben.

### Version 1.0 (12.01.2025)

* Initiale Version.
