class GoodWeModule extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Create a Modbus Gateway instance
        $this->RegisterInstance('ModBus Gateway', '{B43733D4-1A15-4ED6-B098-90FAAE9852DE}');

        // Register properties
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('InverterIP', '');
        $this->RegisterPropertyInteger('PollingInterval', 60);
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Validate properties
        $ip = $this->ReadPropertyString('InverterIP');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->SetStatus(201); // Invalid IP Address
            return;
        }

        $this->SetStatus(102); // Instance is active
    }

    public function RequestData()
    {
        // Fetch data from the inverter using Modbus or API logic
        $this->LogMessage('Fetching data from GoodWe inverter...', KL_NOTIFY);

        // Example logic placeholder
        // $data = $this->FetchGoodWeData();

        // Parse and set variables based on data
        // $this->SetValue('Power', $data['power']);
    }

    private function FetchGoodWeData()
    {
        // Placeholder for Modbus or API fetch logic
        return [
            'power' => 5000,
            'voltage' => 230,
        ];
    }
}
