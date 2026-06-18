<script>
    (() => {
        "use strict";

        const DEFAULT_BAUD_RATE = 9600;
        const CHANNEL_NAME = "scale_active_tab_channel";
        const TAB_ID = `${Date.now()}-${Math.random()}`;

        const channel = "BroadcastChannel" in window
            ? new BroadcastChannel(CHANNEL_NAME)
            : null;

        let isInitializing = false;
        let visibilityDebounce = null;

        class ScaleConnection {
            constructor(baudRate = DEFAULT_BAUD_RATE) {
                this.port = null;
                this.reader = null;
                this.keepReading = false;
                this.isConnected = false;
                this.isReading = false;
                this.BAUD_RATE = baudRate;
                this.lastWeight = "";
            }

            async connect(requestIfNeeded = false) {
                if (!("serial" in navigator)) {
                    throw new Error("Browser tidak mendukung Web Serial API. Gunakan Chrome/Edge.");
                }

                if (this.isConnected && this.port) {
                    return this.port.getInfo();
                }

                const ports = await navigator.serial.getPorts();
                this.port = ports[0] || null;

                if (!this.port && requestIfNeeded) {
                    this.port = await navigator.serial.requestPort();
                }

                if (!this.port) {
                    throw new Error("Port belum diizinkan. Klik Hubungkan Timbangan sekali.");
                }

                // Coba buka port. Kalau gagal (port masih nyangkut dari sesi/tab
                // sebelumnya), tutup paksa lalu coba sekali lagi.
                try {
                    await this.port.open({ baudRate: this.BAUD_RATE });
                } catch (e) {
                    console.warn("port.open() pertama gagal, coba tutup dulu:", e?.name, e?.message);

                    try { await this.port.close(); } catch (_) {}
                    await new Promise((resolve) => setTimeout(resolve, 300));

                    // percobaan kedua — kalau ini gagal, biarkan error naik ke pemanggil
                    await this.port.open({ baudRate: this.BAUD_RATE });
                }

                this.isConnected = true;

                return this.port.getInfo();
            }

            formatWeight(rawValue) {
                const matches = String(rawValue || "").match(/[-+]?\d+(?:[.,]\d+)?/g);
                if (!matches || !matches.length) return "";

                const number = Number(matches[matches.length - 1].replace(",", "."));
                if (!Number.isFinite(number)) return "";

                // return number.toFixed(3).replace(".", ",");
                return String(number).replace(".", ",");
            }

            async startReading(callback) {
                if (!this.port || !this.port.readable || this.isReading) return;

                this.keepReading = true;
                this.isReading = true;

                const decoder = new TextDecoder();
                let buffer = "";

                while (this.port && this.port.readable && this.keepReading) {
                    try {
                        this.reader = this.port.readable.getReader();

                        while (this.keepReading) {
                            const { value, done } = await this.reader.read();

                            if (done || !this.keepReading) break;

                            buffer += decoder.decode(value, { stream: true });

                            const parts = buffer.split(/\r?\n/);
                            buffer = parts.pop() || "";

                            parts.forEach((part) => {
                                this.handleWeight(part, callback);
                            });

                            this.handleWeight(buffer, callback);

                            if (buffer.length > 200) {
                                buffer = buffer.slice(-200);
                            }
                        }
                    } catch (error) {
                        if (this.keepReading) {
                            console.error("Error saat membaca:", error);
                        }
                    } finally {
                        // Selalu lepas lock di sini. close() TIDAK lagi men-null-kan
                        // reader, jadi blok ini yang bertanggung jawab releaseLock.
                        if (this.reader) {
                            try {
                                this.reader.releaseLock();
                            } catch (_) {}

                            this.reader = null;
                        }
                    }

                    if (this.keepReading) {
                        await new Promise((resolve) => setTimeout(resolve, 100));
                    }
                }

                this.isReading = false;
            }

            handleWeight(rawValue, callback) {
                const weight = this.formatWeight(rawValue);

                if (!weight || weight === this.lastWeight) return;

                this.lastWeight = weight;

                if (callback) {
                    callback(weight, rawValue);
                }
            }

            async close() {
                this.keepReading = false;

                // Minta reader berhenti. JANGAN set this.reader = null di sini —
                // biarkan finally di startReading yang melepas lock-nya.
                if (this.reader) {
                    try {
                        await this.reader.cancel();
                    } catch (_) {}
                }

                // Tunggu loop baca benar-benar selesai melepas lock stream
                // sebelum port.close(), kalau tidak port.close() akan reject
                // karena readable masih ter-lock -> port tetap terbuka di OS.
                let waited = 0;
                while (this.isReading && waited < 2000) {
                    await new Promise((resolve) => setTimeout(resolve, 50));
                    waited += 50;
                }

                if (this.port) {
                    try {
                        await this.port.close();
                    } catch (e) {
                        console.warn("port.close() gagal:", e?.name, e?.message);
                    }

                    this.port = null;
                }

                this.isConnected = false;
                this.isReading = false;
            }

            getWeight() {
                return this.lastWeight;
            }

            getWeightTitik() {
                return String(this.lastWeight || "").replace(",", ".");
            }

            getPortInfo() {
                return this.port ? this.port.getInfo() : null;
            }
        }

        const scaleConnection = window.__scaleConnection || new ScaleConnection();
        window.__scaleConnection = scaleConnection;
        window.scaleConnection = scaleConnection;

        function el(id) {
            return document.getElementById(id);
        }

        function isTabActive() {
            return document.visibilityState === "visible";
        }

        function log(message) {
            const logContainer = el("logContainer");

            if (logContainer) {
                const div = document.createElement("div");
                div.textContent = `${new Date().toLocaleTimeString()} - ${message}`;
                logContainer.insertBefore(div, logContainer.firstChild);
            }

            console.log(message);
        }

        function broadcast(data) {
            if (!channel) return;

            channel.postMessage({
                ...data,
                tabId: TAB_ID,
            });
        }

        function updateUI(connected) {
            const status = el("status");
            const portInfo = el("portInfo");
            const readButton = el("readButton");
            const conscale = el("connectButton");

            if (status) {
                status.textContent = connected ? "Status: Terhubung" : "Status: Tidak Terhubung";
            }

            if (conscale) {
                if (connected) {
                    conscale.classList.add("disabled");
                    conscale.innerHTML = "Terhubung";
                } else {
                    conscale.classList.remove("disabled");
                    conscale.innerHTML = "Tidak Terhubung";
                }
            }

            if (readButton) {
                readButton.disabled = !connected;
            }

            const info = scaleConnection.getPortInfo();

            if (portInfo) {
                portInfo.textContent = info
                    ? `Port: VendorID: ${info.usbVendorId || "-"}, ProductID: ${info.usbProductId || "-"}`
                    : "Port: -";
            }
        }

        function tampilkanBerat(weight) {
            const weightElement = el("weight");
            const inputBerat = el("berat_timbangan");

            if (weightElement) {
                weightElement.textContent = `${weight} kg`;
            }

            if (inputBerat) {
                inputBerat.value = String(weight).replace(",", ".");
            }
        }

        async function lepasKoneksiTimbangan() {
            if (!scaleConnection.isConnected && !scaleConnection.port) return;

            await scaleConnection.close();
            updateUI(false);
            log("Koneksi timbangan dilepas");
        }

        async function hubungkanTimbangan(requestIfNeeded = false) {
            if (isInitializing || scaleConnection.isConnected) return true;
            if (!isTabActive()) return false;

            isInitializing = true;

            try {
                await scaleConnection.connect(requestIfNeeded);

                updateUI(true);
                log("Terhubung ke timbangan");

                scaleConnection.startReading(function (weight) {
                    tampilkanBerat(weight);
                });

                return true;
            } catch (error) {
                updateUI(false);
                // tampilkan name + message biar mudah bedakan "Access denied"
                // (port dipakai app/tab lain) vs error lain
                log(`${error.name || "Error"}: ${error.message}`);
                return false;
            } finally {
                isInitializing = false;
            }
        }

        async function hubungkanDenganRetry(totalRetry = 6) {
            for (let i = 1; i <= totalRetry; i++) {
                if (!isTabActive()) return;

                const berhasil = await hubungkanTimbangan(false);

                if (berhasil && scaleConnection.isConnected) {
                    return;
                }

                await new Promise((resolve) => setTimeout(resolve, 500));
            }
        }

        function ambilAlihKoneksiTabAktif() {
            if (!isTabActive()) return;

            broadcast({
                type: "release_scale",
            });

            setTimeout(function () {
                hubungkanDenganRetry(6);
            }, 700);
        }

        function bindBroadcast() {
            if (!channel) return;

            channel.onmessage = async function (event) {
                const data = event.data || {};

                if (data.tabId === TAB_ID) return;

                if (data.type === "release_scale") {
                    await lepasKoneksiTimbangan();

                    broadcast({
                        type: "scale_released",
                    });
                }

                if (data.type === "scale_released") {
                    if (isTabActive() && !scaleConnection.isConnected) {
                        setTimeout(function () {
                            hubungkanDenganRetry(3);
                        }, 300);
                    }
                }
            };
        }

        function bindEvents() {
            const connectButton = el("connectButton");
            const readButton = el("readButton");

            if (connectButton) {
                connectButton.addEventListener("click", function () {
                    hubungkanTimbangan(true);
                });
            }

            if (readButton) {
                readButton.addEventListener("click", function () {
                    log(scaleConnection.getWeight() || "Berat belum terbaca");
                });
            }

            window.addEventListener("focus", function () {
                ambilAlihKoneksiTabAktif();
            });

            document.addEventListener("visibilitychange", function () {
                // debounce supaya pindah-pindah tab cepat tidak memicu
                // buka-tutup port beruntun (penyebab "failed to open")
                clearTimeout(visibilityDebounce);

                visibilityDebounce = setTimeout(function () {
                    if (document.visibilityState === "hidden") {
                        lepasKoneksiTimbangan();
                    } else if (document.visibilityState === "visible") {
                        ambilAlihKoneksiTabAktif();
                    }
                }, 250);
            });

            window.addEventListener("beforeunload", function () {
                lepasKoneksiTimbangan();
            });

            navigator.serial?.addEventListener("disconnect", function () {
                scaleConnection.isConnected = false;
                updateUI(false);
                log("Port timbangan terputus");
            });
        }

        window.getBeratTimbangan = function () {
            return scaleConnection.getWeight();
        };

        window.getBeratTimbanganKoma = function () {
            return scaleConnection.getWeight();
        };

        window.getBeratTimbanganTitik = function () {
            return scaleConnection.getWeightTitik();
        };

        window.ambilBeratSekarang = function () {
            const berat = window.getBeratTimbanganTitik
                ? window.getBeratTimbanganTitik()
                : '';

            if (!berat) {
                alert('Berat belum terbaca / timbangan belum terhubung');
                return '';
            }

            return berat;
        };

        window.kliktimbang = async function (idTarget) {
            const berat = window.ambilBeratSekarang();
            if (!berat) return false;

            const target = $('#' + idTarget);

            if (!target.length) {
                console.log('ID target tidak ditemukan:', idTarget);
                return false;
            }

            if (target.is('input, textarea, select')) {
                target.val(berat);
            } else {
                target.text(berat);
            }

            console.log(berat);

            // set weight scale to weight_realtime
            if (Number(berat) > 0) {
                $('#weight_realtime').val(berat);
            } else {
                $('#weight_realtime').val(0);
            }

            // get weight realtime
            const weight = $('#weight_realtime').val();

            console.log(weight);

            // set weight of input with this id
            $('#' + idTarget).val(weight).trigger('change'); // onchange tidak akan terpanggil ketika value diubah melalui JavaScript, tambahkan trigger('change')
            $('#selscale').val(idTarget);

            console.log( $('#' + idTarget).val(weight) );
            console.log( $('#selscale').val(idTarget) );

            return true;
        };

        // SATU handler DOMContentLoaded saja. Versi sebelumnya punya dua
        // handler identik -> bindBroadcast/bindEvents terpanggil dobel,
        // listener & percobaan connect jadi double (race "failed to open").
        document.addEventListener("DOMContentLoaded", function () {
            bindBroadcast();
            bindEvents();
            updateUI(false);

            setTimeout(function () {
                ambilAlihKoneksiTabAktif();
            }, 500);
        });
    })();

    $(document).ready(function () {
        const timbangan_realtime = $('#weight_realtime');
        const input_timbangan_realtime = '<input type="hidden" id="weight_realtime">';

        if (timbangan_realtime.length === 0) {
            $('body').append(input_timbangan_realtime);
        } else {
            $('#weight_realtime').remove();
            $('body').append(input_timbangan_realtime);
        }
    });
</script>

{{-- Marko --}}
{{-- <script>
    (() => {
        "use strict";

        const DEFAULT_BAUD_RATE = 9600;
        const CHANNEL_NAME = "scale_active_tab_channel";
        const TAB_ID = `${Date.now()}-${Math.random()}`;

        const channel = "BroadcastChannel" in window
            ? new BroadcastChannel(CHANNEL_NAME)
            : null;

        let isInitializing = false;

        class ScaleConnection {
            constructor(baudRate = DEFAULT_BAUD_RATE) {
                this.port = null;
                this.reader = null;
                this.keepReading = false;
                this.isConnected = false;
                this.isReading = false;
                this.BAUD_RATE = baudRate;
                this.lastWeight = "";
            }

            async connect(requestIfNeeded = false) {
                if (!("serial" in navigator)) {
                    throw new Error("Browser tidak mendukung Web Serial API. Gunakan Chrome/Edge.");
                }

                if (this.isConnected && this.port) {
                    return this.port.getInfo();
                }

                const ports = await navigator.serial.getPorts();
                this.port = ports[0] || null;

                if (!this.port && requestIfNeeded) {
                    this.port = await navigator.serial.requestPort();
                }

                if (!this.port) {
                    throw new Error("Port belum diizinkan. Klik Hubungkan Timbangan sekali.");
                }

                await this.port.open({ baudRate: this.BAUD_RATE });
                this.isConnected = true;

                return this.port.getInfo();
            }

            formatWeight(rawValue) {
                const matches = String(rawValue || "").match(/[-+]?\d+(?:[.,]\d+)?/g);
                if (!matches || !matches.length) return "";

                const number = Number(matches[matches.length - 1].replace(",", "."));
                if (!Number.isFinite(number)) return "";

                return number.toFixed(3).replace(".", ",");
            }

            async startReading(callback) {
                if (!this.port || !this.port.readable || this.isReading) return;

                this.keepReading = true;
                this.isReading = true;

                const decoder = new TextDecoder();
                let buffer = "";

                while (this.port && this.port.readable && this.keepReading) {
                    try {
                        this.reader = this.port.readable.getReader();

                        while (this.keepReading) {
                            const { value, done } = await this.reader.read();

                            if (done || !this.keepReading) break;

                            buffer += decoder.decode(value, { stream: true });

                            const parts = buffer.split(/\r?\n/);
                            buffer = parts.pop() || "";

                            parts.forEach((part) => {
                                this.handleWeight(part, callback);
                            });

                            this.handleWeight(buffer, callback);

                            if (buffer.length > 200) {
                                buffer = buffer.slice(-200);
                            }
                        }
                    } catch (error) {
                        if (this.keepReading) {
                            console.error("Error saat membaca:", error);
                        }
                    } finally {
                        if (this.reader) {
                            try {
                                this.reader.releaseLock();
                            } catch (_) {}

                            this.reader = null;
                        }
                    }

                    if (this.keepReading) {
                        await new Promise((resolve) => setTimeout(resolve, 100));
                    }
                }

                this.isReading = false;
            }

            handleWeight(rawValue, callback) {
                const weight = this.formatWeight(rawValue);

                if (!weight || weight === this.lastWeight) return;

                this.lastWeight = weight;

                if (callback) {
                    callback(weight, rawValue);
                }
            }

            async close() {
                this.keepReading = false;

                if (this.reader) {
                    try {
                        await this.reader.cancel();
                    } catch (_) {}

                    this.reader = null;
                }

                if (this.port) {
                    try {
                        await this.port.close();
                    } catch (_) {}

                    this.port = null;
                }

                this.isConnected = false;
                this.isReading = false;
            }

            getWeight() {
                return this.lastWeight;
            }

            getWeightTitik() {
                return String(this.lastWeight || "").replace(",", ".");
            }

            getPortInfo() {
                return this.port ? this.port.getInfo() : null;
            }
        }

        const scaleConnection = window.__scaleConnection || new ScaleConnection();
        window.__scaleConnection = scaleConnection;
        window.scaleConnection = scaleConnection;

        function el(id) {
            return document.getElementById(id);
        }

        function isTabActive() {
            return document.visibilityState === "visible";
        }

        function log(message) {
            const logContainer = el("logContainer");

            if (logContainer) {
                const div = document.createElement("div");
                div.textContent = `${new Date().toLocaleTimeString()} - ${message}`;
                logContainer.insertBefore(div, logContainer.firstChild);
            }

            console.log(message);
        }

        function broadcast(data) {
            if (!channel) return;

            channel.postMessage({
                ...data,
                tabId: TAB_ID,
            });
        }

        function updateUI(connected) {

            console.log(connected);

            const status = el("status");
            const portInfo = el("portInfo");
            const readButton = el("readButton");

            const conscale = el("connectButton");

            console.log(status);

            if (status) {
                status.textContent = connected ? "Status: Terhubung" : "Status: Tidak Terhubung";
            }

            if (conscale) {
                if (connected) {
                    conscale.classList.add("disabled");
                    conscale.innerHTML = "Terhubung";
                } else {
                    conscale.classList.remove("disabled");
                    conscale.innerHTML = "Tidak Terhubung";
                }
            }

            if (readButton) {
                readButton.disabled = !connected;
            }

            const info = scaleConnection.getPortInfo();

            if (portInfo) {
                portInfo.textContent = info
                    ? `Port: VendorID: ${info.usbVendorId || "-"}, ProductID: ${info.usbProductId || "-"}`
                    : "Port: -";
            }


        }

        function tampilkanBerat(weight) {
            const weightElement = el("weight");
            const inputBerat = el("berat_timbangan");

            if (weightElement) {
                weightElement.textContent = `${weight} kg`;
            }

            if (inputBerat) {
                inputBerat.value = String(weight).replace(",", ".");
            }
        }

        async function lepasKoneksiTimbangan() {
            if (!scaleConnection.isConnected && !scaleConnection.port) return;

            await scaleConnection.close();
            updateUI(false);
            log("Koneksi timbangan dilepas");
        }

        async function hubungkanTimbangan(requestIfNeeded = false) {
            if (isInitializing || scaleConnection.isConnected) return true;
            if (!isTabActive()) return false;

            isInitializing = true;

            try {
                await scaleConnection.connect(requestIfNeeded);

                updateUI(true);
                log("Terhubung ke timbangan");

                scaleConnection.startReading(function (weight) {
                    tampilkanBerat(weight);
                });

                return true;
            } catch (error) {
                updateUI(false);
                log(error.message);
                return false;
            } finally {
                isInitializing = false;
            }
        }

        async function hubungkanDenganRetry(totalRetry = 6) {
            for (let i = 1; i <= totalRetry; i++) {
                if (!isTabActive()) return;

                const berhasil = await hubungkanTimbangan(false);

                if (berhasil && scaleConnection.isConnected) {
                    return;
                }

                await new Promise((resolve) => setTimeout(resolve, 500));
            }
        }

        function ambilAlihKoneksiTabAktif() {
            if (!isTabActive()) return;

            broadcast({
                type: "release_scale",
            });

            setTimeout(function () {
                hubungkanDenganRetry(6);
            }, 700);
        }

        function bindBroadcast() {
            if (!channel) return;

            channel.onmessage = async function (event) {
                const data = event.data || {};

                if (data.tabId === TAB_ID) return;

                if (data.type === "release_scale") {
                    await lepasKoneksiTimbangan();

                    broadcast({
                        type: "scale_released",
                    });
                }

                if (data.type === "scale_released") {
                    if (isTabActive() && !scaleConnection.isConnected) {
                        setTimeout(function () {
                            hubungkanDenganRetry(3);
                        }, 300);
                    }
                }
            };
        }

        function bindEvents() {
            const connectButton = el("connectButton");
            const readButton = el("readButton");

            if (connectButton) {
                connectButton.addEventListener("click", function () {
                    hubungkanTimbangan(true);
                });
            }

            if (readButton) {
                readButton.addEventListener("click", function () {
                    log(scaleConnection.getWeight() || "Berat belum terbaca");
                });
            }

            window.addEventListener("focus", function () {
                ambilAlihKoneksiTabAktif();
            });

            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === "hidden") {
                    lepasKoneksiTimbangan();
                }

                if (document.visibilityState === "visible") {
                    ambilAlihKoneksiTabAktif();
                }
            });

            window.addEventListener("beforeunload", function () {
                lepasKoneksiTimbangan();
            });

            navigator.serial?.addEventListener("disconnect", function () {
                scaleConnection.isConnected = false;
                updateUI(false);
                log("Port timbangan terputus");
            });
        }

        window.getBeratTimbangan = function () {
            return scaleConnection.getWeight();
        };

        window.getBeratTimbanganKoma = function () {
            return scaleConnection.getWeight();
        };

        window.getBeratTimbanganTitik = function () {
            return scaleConnection.getWeightTitik();
        };

        document.addEventListener("DOMContentLoaded", function () {
            bindBroadcast();
            bindEvents();
            updateUI(false);

            setTimeout(function () {
                ambilAlihKoneksiTabAktif();
            }, 500);
        });

        // ...........
        window.getBeratTimbanganTitik = function () {
            return scaleConnection.getWeightTitik();
        };

        window.ambilBeratSekarang = function () {
            const berat = window.getBeratTimbanganTitik
                ? window.getBeratTimbanganTitik()
                : '';

            if (!berat) {
                alert('Berat belum terbaca / timbangan belum terhubung');
                return '';
            }

            return berat;
        };

        window.kliktimbang = async function (idTarget) {
            const berat = window.ambilBeratSekarang();
            if (!berat) return false;

            const target = $('#' + idTarget);

            if (!target.length) {
                console.log('ID target tidak ditemukan:', idTarget);
                return false;
            }

            if (target.is('input, textarea, select')) {
                target.val(berat);
            } else {
                target.text(berat);
            }

            console.log(berat);
            
            // set weight scale to weight_realtime
            if (berat > 0) {
                $('#weight_realtime').val(berat)
            }else{
                $('#weight_realtime').val(0)
            }

            // get weight realtime
            let weight = await $('#weight_realtime').val()

            console.log(weight);

            // set weight of input with this id
            $('#' + idTarget).val(weight)
            $('#selscale').val(idTarget)

            return true;
        };

        document.addEventListener("DOMContentLoaded", function () {
            bindBroadcast();
            bindEvents();
            updateUI(false);

            setTimeout(function () {
                ambilAlihKoneksiTabAktif();
            }, 500);
        });
    })();

    async function sendSerialLine() {

        if (type_timbangan == "AND") {
            dataToSend = "S";
        } else {
            dataToSend = "O9";
        }
        lineHistory.unshift(dataToSend);
        historyIndex = -1; // No history entry selected
        dataToSend = dataToSend + "\n";
        await writer.write(dataToSend);
    }

    async function listenToPort() {
        const textDecoder = new TextDecoderStream();
        const readableStreamClosed = port.readable.pipeTo(textDecoder.writable);
        const reader = textDecoder.readable.getReader();

        // Listen to data coming from the serial device.
        while (true) {
            const {
                value,
                done
            } = await reader.read();
            if (done) {
                // Allow the serial port to be closed later.
                console.log('[readLoop] DONE', done);
                reader.releaseLock();
                break;
            }
            // value is a string.
            appendToTerminal(value);
        }
    }

    async function appendToTerminal(newStuff) {

        newStuff = newStuff.replace(/[^\d.-]+/g, ''); 
        if (newStuff.charAt(0) === ".") {
            newStuff = "0";
        }

        newStuff = Number(newStuff);
        let weight = parseFloat(newStuff);

        if (weight > 0) {
            $('#weight_realtime').val(weight)
        }else{
            $('#weight_realtime').val(0)
        }
    }

    async function kliktimbang(id_of_input_element) {

        // get weight realtime
        await sendSerialLine()
        let weight = await $('#weight_realtime').val()

        // set weight of input with this id
        $('#' + id_of_input_element).val(weight)
        $('#selscale').val(id_of_input_element)
    }

    $(document).ready(function() {
        let timbangan_realtime = $('#weight_realtime')
        let input_timbangan_realtime = '<input type="hidden" id="weight_realtime">'

        if (timbangan_realtime.length == 0) {
            $('body').append(input_timbangan_realtime)
        } else {
            $('#weight_realtime').remove()
            $('body').append(input_timbangan_realtime)
        }
    })
</script> --}}

