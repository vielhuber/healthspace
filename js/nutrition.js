let $mealForm = document.getElementById('meal-upload');
let $mealPhoto = document.getElementById('meal-photo');
let $mealCamera = document.getElementById('meal-camera');
let $mealCameraPhoto = document.getElementById('meal-camera-photo');
let $mealDialog = document.getElementById('meal-dialog');
let $mealClose = document.getElementById('meal-close');
let $mealPreview = document.getElementById('meal-preview');
let $mealStatus = document.getElementById('meal-upload-status');
let mealBusy = false;
let mealPreviewUrl;

let setMealBusy = busy => {
    mealBusy = busy;
    $mealForm.setAttribute('aria-busy', String(busy));
    $mealCamera.disabled = busy;
    $mealClose.disabled = busy;
    $mealForm.querySelectorAll('button, input:not([type="hidden"])').forEach($control => {
        $control.disabled = busy;
    });
};

$mealCamera.addEventListener('click', () => {
    $mealDialog.showModal();
    $mealCameraPhoto.value = '';
    $mealCameraPhoto.click();
});
$mealClose.addEventListener('click', () => $mealDialog.close());
$mealDialog.addEventListener('cancel', event => {
    if (mealBusy) {
        event.preventDefault();
    }
});
$mealCameraPhoto.addEventListener('change', () => {
    $mealPhoto.files = $mealCameraPhoto.files;
    $mealPhoto.dispatchEvent(new Event('change'));
});

$mealPhoto.addEventListener('change', async () => {
    let photo = $mealPhoto.files[0];
    if (!photo || mealBusy) {
        return;
    }
    setMealBusy(true);
    $mealPreview.hidden = true;
    $mealStatus.textContent = 'Foto wird vorbereitet …';
    $mealStatus.classList.remove('health-entry__message--error');
    let sourceUrl = URL.createObjectURL(photo);
    try {
        let $image = new Image();
        $image.src = sourceUrl;
        await $image.decode();
        let scale = Math.min(1, 1600 / Math.max($image.naturalWidth, $image.naturalHeight));
        let $canvas = document.createElement('canvas');
        $canvas.width = Math.max(1, Math.round($image.naturalWidth * scale));
        $canvas.height = Math.max(1, Math.round($image.naturalHeight * scale));
        $canvas.getContext('2d').drawImage($image, 0, 0, $canvas.width, $canvas.height);
        let blob = await new Promise(resolve => $canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob) {
            throw new Error('photo conversion failed');
        }
        let transfer = new DataTransfer();
        transfer.items.add(new File([blob], 'meal.jpg', { type: 'image/jpeg' }));
        $mealPhoto.files = transfer.files;
        if (mealPreviewUrl) {
            URL.revokeObjectURL(mealPreviewUrl);
        }
        mealPreviewUrl = URL.createObjectURL(blob);
        $mealPreview.src = mealPreviewUrl;
        $mealPreview.hidden = false;
    } catch {
        $mealStatus.textContent = 'Das Foto konnte nicht geöffnet werden. Bitte JPEG, PNG oder WebP auswählen.';
        $mealStatus.classList.add('health-entry__message--error');
        setMealBusy(false);
        return;
    } finally {
        URL.revokeObjectURL(sourceUrl);
    }
    setMealBusy(false);
    $mealStatus.textContent = '';
    $mealForm.requestSubmit();
});

$mealForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (mealBusy) {
        return;
    }
    let body = new FormData($mealForm);
    setMealBusy(true);
    $mealStatus.textContent = 'Foto wird hochgeladen und Kalorien werden erkannt …';
    $mealStatus.classList.remove('health-entry__message--error');
    try {
        let response = await fetch($mealForm.action, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body
        });
        if (!response.headers.get('content-type')?.includes('application/json')) {
            $mealStatus.textContent =
                'Keine gültige Antwort. Bitte die Seite neu laden und gegebenenfalls erneut anmelden.';
            $mealStatus.classList.add('health-entry__message--error');
            return;
        }
        let result = await response.json();
        if (!response.ok || !result.success) {
            $mealStatus.textContent = result.error || 'Die Mahlzeit konnte nicht gespeichert werden.';
            $mealStatus.classList.add('health-entry__message--error');
            return;
        }
        window.location.replace('/?page=nutrition&meal_status=saved');
    } catch {
        $mealStatus.textContent =
            'Verbindung unterbrochen. Erneut versuchen: dieselbe Mahlzeit wird nicht doppelt gespeichert.';
        $mealStatus.classList.add('health-entry__message--error');
    } finally {
        setMealBusy(false);
    }
});
