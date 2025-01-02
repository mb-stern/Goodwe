<?php
class Goodwe extends IPSModule
{
    public function Create() {
		
        //Never delete this line!
        parent::Create();
        
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        
        $this->RegisterPropertyInteger("Poller", 0);
        $this->RegisterPropertyInteger("Phase", 1);
        
        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");

    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        $registers = $this->GetRegisterList();
        foreach ($registers as $index => $register) {
            $this->RegisterVariableFloat(
                $this->GenerateIdent($register['Name']),
                $register['Name'] . " (" . $register['Unit'] . ")",
                "",
                $index + 1
            );
        }
    
        // Timer-Intervall setzen
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }
    

    private function GetRegisterList()
    {
        return [
            ["Name" => "PV1 Voltage", "Address" => 35103, "Unit" => "V", "Factor" => 0.1],
            ["Name" => "PV1 Current", "Address" => 35104, "Unit" => "A", "Factor" => 0.1],
            ["Name" => "PV1 Power", "Address" => 35105, "Unit" => "W", "Factor" => 1],
            // Weitere Register hinzufügen...
        ];
    }

    private function GetRegisterMapping()
{
    return [
        ["Name" => "PV1 Voltage", "Address" => 35103, "Length" => 2, "Factor" => 0.1],
        ["Name" => "PV1 Current", "Address" => 35104, "Length" => 2, "Factor" => 0.1],
        ["Name" => "PV1 Power", "Address" => 35105, "Length" => 2, "Factor" => 1],
        // Weitere Register hinzufügen..
    ];
}


    public function RequestRead()
    {
        $registers = $this->GetRegisterMapping(); // Wir nutzen hier eine zentrale Mapping-Tabelle
        foreach ($registers as $register) {
            // JSON-Anfrage zusammenstellen
            $jsonRequest = json_encode([
                "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3, // Read Holding Register
                "Address" => $register['Address'],
                "Quantity" => $register['Length'] // Anzahl der zu lesenden Register
            ]);
    
            // Debug: Gesendete Anfrage
            $this->SendDebug("Request for " . $register['Name'], $jsonRequest, 0);
    
            // Anfrage senden
            $response = $this->SendDataToParent($jsonRequest);
    
            if ($response === false) {
                // Debug: Fehlerhafte Antwort
                $this->SendDebug("Response for " . $register['Name'], "No response received", 0);
            } else {
                // Debug: Empfangene Antwort
                $this->SendDebug("Response for " . $register['Name'], bin2hex($response), 0);
    
                // Daten verarbeiten
                $data = unpack("n*", $response);
                $value = ($data[1] + ($data[2] << 16)) * $register['Factor']; // Skalierung anwenden
                SetValue($this->GetIDForIdent($register['Name']), $value);
            }
        }
    }
    
    private function GenerateIdent(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '_', $name);
    }

}