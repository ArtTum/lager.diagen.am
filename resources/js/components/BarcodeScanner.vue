<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

const emit = defineEmits(['detected']);
const panelOpen = ref(false);
const scanning = ref(false);
const starting = ref(false);
const status = ref('Տեսախցիկը գործարկելու համար սեղմեք «Միացնել տեսախցիկը»։');
const video = ref(null);
let stream = null;
let detector = null;
let frame = 0;
let cameraVersion = 0;

function stopCamera() {
    cameraVersion += 1;
    starting.value = false;
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
    const version = cameraVersion;
    try {
        const matches = await detector.detect(video.value);
        if (version !== cameraVersion || !scanning.value) return;
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
    if (version === cameraVersion && scanning.value) frame = requestAnimationFrame(scanFrame);
}

async function startCamera() {
    if (starting.value || scanning.value) return;
    const version = ++cameraVersion;
    starting.value = true;
    panelOpen.value = true;
    status.value = 'Ստուգվում է տեսախցիկի հասանելիությունը…';
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
        status.value = 'Այս դիտարկիչը չի աջակցում տեսախցիկով սկանավորմանը։ Մուտքագրեք կոդը դաշտում կամ օգտագործեք USB շտրիխ սկաներ։';
        starting.value = false;
        return;
    }

    try {
        const supported = await window.BarcodeDetector.getSupportedFormats();
        if (version !== cameraVersion) return;
        const formats = ['code_39', 'code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code', 'data_matrix', 'itf', 'codabar']
            .filter((format) => supported.includes(format));
        if (!formats.length) {
            status.value = 'Այս դիտարկիչը չի աջակցում համապատասխան շտրիխ կամ QR ձևաչափերի։ Կարող եք մուտքագրել կոդը։';
            return;
        }
        detector = new window.BarcodeDetector({ formats });
        const acquiredStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        if (version !== cameraVersion) {
            acquiredStream.getTracks().forEach((track) => track.stop());
            return;
        }
        stream = acquiredStream;
        await nextTick();
        if (version !== cameraVersion || !video.value) return;
        video.value.srcObject = stream;
        await video.value.play();
        if (version !== cameraVersion) return;
        scanning.value = true;
        status.value = 'Ուղղեք շտրիխը կամ QR կոդը տեսախցիկին։';
        frame = requestAnimationFrame(scanFrame);
    } catch (error) {
        if (version !== cameraVersion) return;
        stopCamera();
        status.value = error?.name === 'NotAllowedError'
            ? 'Տեսախցիկի թույլտվությունը մերժված է։ Կարող եք մուտքագրել կոդը կամ օգտագործել USB շտրիխ սկաներ։'
            : (error?.name === 'NotFoundError' ? 'Տեսախցիկ չի գտնվել։ Կարող եք մուտքագրել կոդը։' : 'Տեսախցիկը հասանելի չէ։ Ստուգեք թույլտվությունը և փորձեք կրկին։');
    } finally {
        if (version === cameraVersion) starting.value = false;
    }
}

onBeforeUnmount(stopCamera);
</script>

<template>
    <div class="barcode-scanner">
        <button class="secondary-button" type="button" :disabled="starting || scanning" @click="startCamera"><AppIcon name="camera" />{{ scanning ? 'Տեսախցիկը միացված է' : 'Սկանավորել տեսախցիկով' }}</button>
        <section v-if="panelOpen" class="barcode-camera-panel" aria-live="polite">
            <video ref="video" playsinline muted aria-label="Շտրիխ կոդի տեսախցիկի պատկերը"></video>
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
