<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterAttributeString("SelectedRegisters", "[]");

        // Diese Zeile ermöglicht das Speichern der Checkbox-Änderungen in der form.json
        $this->RegisterPropertyString("SelectedRegisters", "[]");

        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Lade die gespeicherten ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        // Debugging: Zeige den Inhalt von SelectedRegisters an
        $this->SendDebug("SelectedRegisters", json_encode($selectedRegisters), 0);

        foreach ($selectedRegisters as $register) {
            // Prüfe, ob die erforderlichen Schlüssel vorhanden sind
            if (!isset($register['address']) || !isset($register['name']) || !isset($register['unit'])) {
                $this->SendDebug("Error", "Ein Register hat fehlende Schlüssel: " . json_encode($register), 0);
                continue;
            }

            $ident = "Addr" . $register['address'];

            // Prüfen, ob die Variable existiert, und falls nicht, erstellen
            if (!$this->GetIDForIdent($ident)) {
                $this->RegisterVariableFloat(
                    $ident,
                    $register['name'],
                    $this->GetVariableProfile($register['unit']),
                    0
                );
            }
        }
    }

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        foreach ($selectedRegisters as $register) {
            if (isset($register['selected']) && $register['selected']) {
                $value = $this->ReadRegister((int)$register['address'], $register['type'], (float)$register['scale']);
                $ident = "Addr" . $register['address'];

                if ($this->GetIDForIdent($ident)) {
                    SetValue($this->GetIDForIdent($ident), $value);
                }
            }
        }
    }

    private function LoadRegisters()
    {
        $registers = $this->GetRegisters(); // Holt alle verfügbaren Register
        $selectedRegisters = json_decode($this->ReadAttributeString("SelectedRegisters"), true);
        $existingSelection = array_column($selectedRegisters, 'selected', 'address');

        $values = [];
        foreach ($registers as $register) {
            $values[] = [
                "address"  => $register['address'] ?? 0, // Fallback für fehlende Werte
                "name"     => $register['name'] ?? "Unknown",
                "type"     => $register['type'] ?? "U16",
                "unit"     => $register['unit'] ?? "N/A",
                "scale"    => $register['scale'] ?? 1,
                "selected" => $existingSelection[$register['address']] ?? false
            ];
        }

        // Formularfeld aktualisieren
        $this->UpdateFormField("SelectedRegisters", "values", json_encode($values));

        // Auswahl speichern
        $this->WriteAttributeString("SelectedRegisters", json_encode($values));
    }

    private function ReadRegister(int $address, string $type, float $scale)
    {
        $quantity = ($type === "U32" || $type === "S32") ? 2 : 1;

        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity
        ]));

        if ($response === false || strlen($response) < (2 * $quantity + 2)) {
            $this->SendDebug("Error", "No or incomplete response for Register $address", 0);
            return 0;
        }

        $data = unpack("n*", substr($response, 2));
        $value = 0;

        switch ($type) {
            case "U16":
                $value = $data[1];
                break;
            case "S16":
                $value = ($data[1] & 0x8000) ? -((~$data[1] & 0xFFFF) + 1) : $data[1];
                break;
            case "U32":
                $value = ($data[1] << 16) | $data[2];
                break;
            case "S32":
                $combined = ($data[1] << 16) | $data[2];
                $value = ($data[1] & 0x8000) ? -((~$combined & 0xFFFFFFFF) + 1) : $combined;
                break;
            default:
                $this->SendDebug("Error", "Unknown type for Register $address: $type", 0);
        }

        return $value / $scale;
    }

    private function GetRegisters()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1]
        ];
    }

    private function GetVariableProfile(string $unit)
    {
        switch ($unit) {
            case "V":
                return "~Volt";
            case "A":
                return "~Ampere";
            case "W":
                return "~Watt";
            case "kWh":
                return "~Electricity";
            default:
                return ""; // Rückgabe eines Standardwerts, falls die Einheit nicht bekannt ist
        }
    }
}
