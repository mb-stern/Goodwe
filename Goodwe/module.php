<?php

class Goodwe extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
    }

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("SelectedRegisters", "[]"); // Auswahl der Register
        $this->RegisterPropertyInteger("Poller", 0);

        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Ausgewählte Register aus den Eigenschaften abrufen
        $selectedRegisters = json_decode($this->ReadPropertyString('SelectedRegisters'), true);
    
        // Variablen für die ausgewählten Register erstellen
        foreach ($this->Registers() as $register) {
            if (in_array($register['address'], $selectedRegisters)) {
                $profileInfo = $this->GetVariableProfile($register['unit'], $register['scale']);
    
                $this->RegisterVariableFloat(
                    "Addr{$register['address']}",
                    $register['name'],
                    $profileInfo['profile'],
                    0
                );
    
                if ($register['action']) {
                    $this->EnableAction("Addr{$register['address']}");
                }
            }
        }
    }
    
    public function RequestRead()
    {
        foreach ($this->Registers() as $register) {
            // Modbus-Anfrage senden
            $quantity = ($register['type'] === "U32" || $register['type'] === "S32") ? 2 : 1;
    
            $response = $this->SendDataToParent(json_encode([
                "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address"  => $register['address'],
                "Quantity" => $quantity,
                "Data"     => ""
            ]));
    
            // Fehlerbehandlung
            if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                $this->SendDebug("Error", "No or incomplete response for Register {$register['address']}", 0);
                continue; // Verbleibt in der foreach-Schleife
            }
    
            // Antwort debuggen
            $this->SendDebug("Raw Response for Register {$register['address']}", bin2hex($response), 0);
    
            // Antwortdaten extrahieren
            $data = unpack("n*", substr($response, 2));
            $value = 0;
    
            // Werte basierend auf Typ interpretieren
            switch ($register['type']) {
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
                    $this->SendDebug("Error", "Unknown type for Register {$register['address']}: {$register['type']}", 0);
                    break; // Nur den Switch-Block verlassen
            }
    
            // Wert skalieren
            $scaledValue = $value / $register['scale'];
    
            // Debugging des interpretierten Werts
            $this->SendDebug("Parsed Value for Register {$register['address']}", $scaledValue, 0);
    
            // Wert in die zugehörige Variable schreiben
            SetValue($this->GetIDForIdent("Addr" . $register['address']), $scaledValue);
        }
    }
    
    public function ReloadRegisters()
    {
        // Register aus der Funktion abrufen
        $registers = $this->Registers();
    
        // Optionen für das Auswahlfeld vorbereiten
        $options = [];
        foreach ($registers as $register) {
            $options[] = [
                'label' => $register['name'] . " (Addr: {$register['address']})",
                'value' => $register['address']
            ];
        }
    
        // Auswahlfeld aktualisieren
        $this->UpdateFormField('SelectedRegisters', 'options', json_encode($options));
        $this->UpdateFormField('SelectedRegisters', 'value', json_encode([])); // Standardmäßig leer
    }
    
    private function Registers()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10, "quantity" => 1],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10, "quantity" => 1],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10, "quantity" => 2],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10, "quantity" => 1],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1, "quantity" => 2]
        ];
    }
}
