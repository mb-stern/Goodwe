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
            ["Name" => "PV2 Voltage", "Address" => 35107, "Unit" => "V", "Factor" => 0.1],
            // Weitere Register hinzufügen...
        ];
    }

    public function RequestRead()
    {
        $registers = $this->GetRegisterList();
        foreach ($registers as $register) {
            $response = $this->SendDataToParent(json_encode([
                "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address" => $register["Address"],
                "Quantity" => 1
            ]));
    
            if ($response !== false) {
                $data = unpack("n*", $response);
                $value = $data[1] * $register["Factor"]; // Skalierung anwenden
                SetValue($this->GetIDForIdent($this->GenerateIdent($register['Name'])), $value);
            } else {
                $this->SendDebug("Error", "No response for " . $register['Name'], 0);
            }
        }
    }

    private function GenerateIdent(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '_', $name);
    }

}