<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';

const emit = defineEmits(['detected']);
const panelOpen = ref(false);
const scanning = ref(false);
const status = ref('Տեսախցիկը գործարկելու համար սեղմեք «Միացնել տեսախցիկը»։');
const video = ref(null);
let stream = null;
let detector = null;
let frame = 0;

function stopCamera() {
    scanning.value = false;
    if (frame) cancelAnimationFrame(frame);
    frame = 0;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    if (video.value) video.value.srcObject = null;
}

function close() {
    stopCamera();
    panelOpen.value = false;
}

async function scanFrame() {
    if (!scanning.value || !detector || !video.value) return;
    try {
        const matches = await detector.detect(video.value);
        const value = matches.find((match) => match.rawValue?.trim())?.rawValue?.trim();
        if (value) {
            status.value = `Կոդը ճանաչվեց՝ ${value}`;
            emit('detected', value);
            close();
            return;
        }
    } catch {
        // A frame can be undecodable while the camera is moving; keep scanning.
    }
    if (scanning.value) frame = requestAnimationFrame(scanFrame);
}

async function startCamera() {
    panelOpen.value = true;
    status.value = 'Ստուգվում է տեսախցիկի հասանելիությունը…';
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
        status.value = 'Այս դիտարկիչը չի աջակցում տեսախցիկով սկանավորմանը։ Մուտքագրեք կոդը դաշտում կամ օգտագործեք USB շտրիխ սկաներ։';
        return;
    }

    try {
        const supported = await window.BarcodeDetector.getSupportedFormats();
        const formats = ['code_39', 'code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code', 'data_matrix', 'itf', 'codabar']
            .filter((format) => supported.includes(format));
        if (!formats.length) {
            status.value = 'Այս դիտարկիչը չի աջակցում համապատասխան շտրիխ կամ QR ձևաչափերի։ Կարող եք մուտքագրել կոդը։';
            return;
        }
        detector = new window.BarcodeDetector({ formats });
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        await nextTick();
        video.value.srcObject = stream;
        await video.value.play();
        scanning.value = true;
        status.value = 'Ուղղեք շտրիխը կամ QR կոդը տեսախցիկին։';
        frame = requestAnimationFrame(scanFrame);
    } catch (error) {
        stopCamera();
        status.value = error?.name === 'NotAllowedError'
            ? 'Տեսախցիկի թույլտվությունը մերժված է։ Կարող եք մուտքագրել կոդը կամ օգտագործել USB շտրիխ սկաներ։'
            : (error?.name === 'NotFoundError' ? 'Տեսախցիկ չի գտնվել։ Կարող եք մուտքագրել կոդը։' : 'Տեսախցիկը հասանելի չէ։ Ստուգեք թույլտվությունը և փորձեք կրկին։');
    }
}

onBeforeUnmount(stopCamera);
</script>

<template>
    <div class="barcode-scanner">
        <button class="secondary-button" type="button" :disabled="scanning" @click="startCamera">{{ scanning ? 'Տեսախցիկը միացված է' : 'Սկանավորել տեսախցիկով' }}</button>
        <section v-if="panelOpen" class="barcode-camera-panel" aria-live="polite">
            <video v-if="scanning" ref="video" playsinline muted aria-label="Շտրիխ կոդի տեսախցիկի պատկերը"></video>
            <div class="barcode-camera-status">
                <p>{{ status }}</p>
                <button class="secondary-button" type="button" @click="close">Փակել տեսախցիկը</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.barcode-scanner{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.barcode-camera-panel{display:grid;grid-template-columns:minmax(220px,1fr) minmax(220px,1fr);align-items:center;gap:16px;width:100%;padding:14px;border:1px solid var(--line,#e5eaf3);border-radius:16px;background:#f8faff}.barcode-camera-panel video{width:100%;max-height:280px;object-fit:cover;border-radius:12px;background:#101828}.barcode-camera-status p{margin:0 0 12px;color:var(--muted,#78859f)}@media(max-width:640px){.barcode-camera-panel{grid-template-columns:1fr}}
</style>
