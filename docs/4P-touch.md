Here is the updated markdown file containing all the specific technical details, command examples, and status field explanations from the page.

# Remote FOTA: GPS Tracker Server Portal Configuration

**Source URL:** [4P-Touch Documentation](https://www.4p-touch.com/Remote-FOTA-send-the-TCP-command-to-change-the-GPS-tracker-device-server-portal-id40081706.html)

---

## 1. Server Migration Overview

If you wish to monitor 4P-Touch devices on your own server portal, you must first complete the server protocol configuration on your end. Once the protocol is ready, all data will report to your server properly.

Because devices are initially bound to the 4P-Touch AWS server, migration requires a Remote FOTA (Firmware Over-the-Air) command to redirect the device to your server IP and port.

### TCP Command Syntax

The command sent from the server to the device follows this format:

* **Format:** `[CS*YYYYYYYYYY*LEN*IP,IP or URL,port]`

* **Example:** `[3G*8800000015*0014*IP,113.81.229.9,5900]`


---

## 2. Post-FOTA Instructions

After the FOTA update is sent, the most important step is to **take the device outdoors and restart it several times** until it shifts to your server. This ensures the device has a strong network connection to process the command.

### Restart Methods

1. **Manual:** Use the device menu: **Settings -> Restart**.


2. **SMS Remote Reset:** Send the following SMS to the SIM card number in the watch/tracker:
* `pw,123456,reset#`




---

## 3. Verifying Connection (Status Check)

To check the device status on your smartphone, send the following SMS command to the device:

* **SMS Command:** `pw,123456,ts#`


### Understanding the Status Reply (`ts#`)

When the device replies, you can verify the connection using the following fields:

| Field | Example Value | Description |
| --- | --- | --- |
| **ver** | `G4P_EMMC_HJ_...` | Firmware Version number and build date

 |
| **ID** | `3004627638` | Unique Device ID number

 |
| **imei** | `866930046276383` | Device IMEI number

 |
| **url** | `54.169.10.136` | **Current Server Portal IP** (Should match your server)

 |
| **port** | `8001` | **Current Server Portal Port**<br> |
| **upload** | `6000` | Data upload time interval

 |
| **lk** | `300` | Linkkeep heartbeat interval

 |
| **batlevel** | `50` | Battery status percentage

 |
| **GPS** | `OK(10)` | `OK` = Outdoor signal; `NO` = Indoor or weak signal

 |
| **NET** | `OK(100)` | `OK` = Connected to network and online; `NO` = Disconnected

 |
| **wifiOpen** | `true` | WIFI network or hotspots are valid

 |
| **gprsOpen** | `true` | GPRS data status (valid/invalid)

 |

---

## 4. Final Calibration

Once the device appears on your server:

* **Outdoor Sync:** Restart the device in an open area to get the first GPS map position.


* **Satellite Sync:** This syncs local coordinates (Latitude/Longitude), which helps improve the accuracy of indoor positioning later.



> **Note:** This process works both ways. If the device is on your server portal, you can use the same FOTA method to change it to any other server portal.
> 
>
