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
    
        // Liste der Modbus-Adressen (aus der Vorlage übernommen)
        $addresses = [
            ["Name" => "Leistung Gesamt", "Address" => 35301, "Profile" => "Watt.I", "Factor" => 1],
            ["Name" => "Wechselrichter Temperatur", "Address" => 35174, "Profile" => "~Temperature", "Factor" => 0.1],
            ["Name" => "Erzeugung Tag", "Address" => 35193, "Profile" => "~Electricity", "Factor" => 0.1],
            ["Name" => "Erzeugung Gesamt", "Address" => 35191, "Profile" => "~Electricity", "Factor" => 0.1],
            // Weitere Einträge...
        ];
    
        // Variablen dynamisch registrieren
        foreach ($addresses as $index => $address) {
            $this->RegisterVariableFloat(
                $this->GenerateIdent($address['Name']),
                $address['Name'],
                $address['Profile'],
                $index + 1
            );
        }
    
        // Timer-Intervall setzen
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }
    
    public function RequestRead()
    {
        $addresses = [
            ["Name" => "Leistung Gesamt", "Address" => 35301, "Factor" => 1],
            ["Name" => "Wechselrichter Temperatur", "Address" => 35174, "Factor" => 0.1],
            ["Name" => "Erzeugung Tag", "Address" => 35193, "Factor" => 0.1],
            ["Name" => "Erzeugung Gesamt", "Address" => 35191, "Factor" => 0.1],
            // Weitere Einträge...
        ];
    
        foreach ($addresses as $address) {
            $response = $this->SendDataToParent(json_encode([
                "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address" => $address["Address"],
                "Quantity" => 1
            ]));
    
            if ($response !== false) {
                $data = unpack("n*", $response);
                $value = $data[1] * $address["Factor"]; // Skalierung anwenden
                SetValue($this->GetIDForIdent($this->GenerateIdent($address['Name'])), $value);
            } else {
                $this->SendDebug("Error", "No response for " . $address['Name'], 0);
            }
        }
    }

    private function GenerateIdent(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9]/', '_', $name);
}

    
}