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
    
        // Register Variablen
        $registers = $this->GetRegisterList();
        foreach ($registers as $index => $register) {
            $this->RegisterVariableFloat(
                $this->GenerateIdent($register['Name']),
                $register['Name'],
                $register['Profile'],
                $index + 1
            );
        }
    
        // Timer für Abfragen setzen
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }
    
    
    public function RequestRead()
    {
        $registers = $this->GetRegisterList();
        foreach ($registers as $register) {
            $response = $this->SendDataToParent(json_encode([
                "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address" => $register['Address'],
                "Quantity" => 1
            ]));
    
            if ($response !== false) {
                $data = unpack("n*", $response);
                $value = $data[1] * $register['Factor']; // Skalierung anwenden
                SetValue($this->GetIDForIdent($this->GenerateIdent($register['Name'])), $value);
            } else {
                $this->SendDebug("Error", "No response for " . $register['Name'], 0);
            }
        }
    }
    
    private function GetRegisterList()
    {
        return [
            ["Name" => "Leistung Gesamt", "Address" => 35301, "Profile" => "Watt.I", "Factor" => 1],
            ["Name" => "Wechselrichter Temperatur", "Address" => 35174, "Profile" => "~Temperature", "Factor" => 0.1],
            ["Name" => "Erzeugung Tag", "Address" => 35193, "Profile" => "~Electricity", "Factor" => 0.1],
            ["Name" => "Erzeugung Gesamt", "Address" => 35191, "Profile" => "~Electricity", "Factor" => 0.1],
            ["Name" => "Spannung String West", "Address" => 35103, "Profile" => "~Volt", "Factor" => 0.1],
            ["Name" => "Strom String West", "Address" => 35104, "Profile" => "~Ampere", "Factor" => 0.1],
            // Weitere Register hinzufügen...
        ];
    }
        
}