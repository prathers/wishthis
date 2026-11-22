/**
 * Import a wishlist from Ønskeskyen.
 *
 * All text from Ønskeskyen is inserted with `textContent`, never as HTML.
 */
document.addEventListener('DOMContentLoaded', function () {
    const apiUrl  = '/index.php?page=api&module=import';
    const strings = JSON.parse(document.getElementById('import-strings').textContent);

    const fetchForm      = document.querySelector('.import-fetch');
    const fetchButton    = document.querySelector('.import-fetch-button');
    const urlInput       = document.getElementById('import-url');
    const errorBox       = document.querySelector('.import-error');
    const preview        = document.querySelector('.import-preview');
    const submitForm     = document.querySelector('.import-submit');
    const submitButton   = document.querySelector('.import-submit-button');
    const nameInput      = document.getElementById('import-name');
    const countLine      = document.querySelector('.import-count');
    const selectAll      = document.getElementById('import-select-all');
    const wishesList     = document.querySelector('.import-wishes');
    const emptyBox       = document.querySelector('.import-empty');
    const wishTemplate   = document.getElementById('import-wish-template');
    const priceFormatter = new Intl.NumberFormat(wishthis.locale, { minimumFractionDigits: 0, maximumFractionDigits: 2 });

    /** The link the preview was fetched from; the import re-fetches it on the server. */
    let fetchedUrl = '';

    /** Requests take seconds (the server asks GoWish); ignore repeated clicks/Enter meanwhile. */
    let busy = false;

    /** Server messages come HTML-encoded from __(); decode them to plain text. */
    function decode(html) {
        return new DOMParser().parseFromString(html, 'text/html').documentElement.textContent;
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden      = false;
    }

    function hideError() {
        errorBox.hidden      = true;
        errorBox.textContent = '';
    }

    function checkboxes() {
        return Array.from(wishesList.querySelectorAll('input[name="wish_ids[]"]'));
    }

    function updateSubmitButton() {
        const selected = checkboxes().filter(checkbox => checkbox.checked).length;

        submitButton.textContent = strings.importWishes.replace('%d', selected);
        submitButton.disabled    = 0 === selected;
        selectAll.checked        = selected === checkboxes().length && selected > 0;
    }

    function hostOf(url) {
        try {
            return new URL(url).hostname.replace(/^www\./, '');
        } catch (error) {
            return '';
        }
    }

    function renderPreview(data) {
        wishesList.replaceChildren();

        nameInput.value       = data.title;
        countLine.textContent = strings.wishesFound.replace('%d', data.wishes.length);

        data.wishes.forEach(function (wish, index) {
            const item     = wishTemplate.content.firstElementChild.cloneNode(true);
            const checkbox = item.querySelector('input');
            const label    = item.querySelector('label');
            const image    = item.querySelector('img');

            checkbox.value = wish.id;
            checkbox.id    = 'import-wish-' + index;
            label.htmlFor  = checkbox.id;

            if (wish.image.startsWith('https://')) {
                image.src = wish.image;
            } else {
                image.src = wishthis.wish.no_image;
            }

            item.querySelector('.import-wish-title').textContent       = wish.title;
            item.querySelector('.import-wish-description').textContent = wish.description;
            item.querySelector('.import-wish-price').textContent       = null === wish.price ? '–' : priceFormatter.format(wish.price);
            item.querySelector('.import-wish-host').textContent        = wish.url ? hostOf(wish.url) : strings.noLink;

            checkbox.addEventListener('change', updateSubmitButton);

            wishesList.appendChild(item);
        });

        emptyBox.hidden      = data.wishes.length > 0;
        emptyBox.textContent = strings.noWishes;
        preview.hidden       = false;

        updateSubmitButton();
    }

    selectAll.addEventListener('change', function () {
        checkboxes().forEach(checkbox => checkbox.checked = selectAll.checked);
        updateSubmitButton();
    });

    fetchForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (busy) {
            return;
        }

        busy = true;
        hideError();

        preview.hidden = true;
        fetchButton.classList.add('loading', 'disabled');

        const url = urlInput.value.trim();

        fetch(apiUrl + '&url=' + encodeURIComponent(url))
        .then(response => response.json())
        .then(function (response) {
            if (response.error) {
                showError(decode(response.error));

                return;
            }

            fetchedUrl = url;
            renderPreview(response.data);
        })
        .catch(() => showError(strings.genericError))
        .finally(function () {
            busy = false;
            fetchButton.classList.remove('loading', 'disabled');
        });
    });

    submitForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (busy || submitButton.disabled) {
            return;
        }

        busy = true;
        hideError();

        const formData = new FormData();

        formData.append('url', fetchedUrl);
        formData.append('wishlist_name', nameInput.value);

        checkboxes()
        .filter(checkbox => checkbox.checked)
        .forEach(checkbox => formData.append('wish_ids[]', checkbox.value));

        submitButton.classList.add('loading', 'disabled');

        fetch(apiUrl, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(function (response) {
            if (response.error) {
                showError(decode(response.error));
                busy = false;
                submitButton.classList.remove('loading', 'disabled');

                return;
            }

            /** Stay busy: the page is navigating away. */
            window.location.href = '/index.php?page=wishlists&id=' + encodeURIComponent(response.data.wishlistId);
        })
        .catch(function () {
            showError(strings.genericError);
            busy = false;
            submitButton.classList.remove('loading', 'disabled');
        });
    });
});
