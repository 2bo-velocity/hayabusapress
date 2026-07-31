<?php /* SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE */ ?>
{% include 'header' %}

<div class="hbp-contact-wrap">
    <h1 class="hbp-page-title">{@ hbp-contact.title @}</h1>

    {% if $sent %}
    <p class="hbp-contact-success">{@ hbp-contact.sent @}</p>
    <p><a href="/">{@ back_home @}</a></p>
    {% else %}
        {% if $error %}
        <p class="hbp-contact-error">{{ $error }}</p>
        {% endif %}

        <form method="post" class="hbp-contact-form">
            <!-- Honeypot (spam-bot defense) -->
            <input type="text" name="hbp_hp_email_field" style="display:none" tabindex="-1" autocomplete="off">

            <div class="hbp-form-group">
                <label>{@ hbp-contact.name @}</label>
                <input type="text" name="contact_name" required value="{{ $inputName }}">
            </div>
            <div class="hbp-form-group">
                <label>{@ hbp-contact.email @}</label>
                <input type="email" name="contact_email" required value="{{ $inputEmail }}">
            </div>
            <div class="hbp-form-group">
                <label>{@ hbp-contact.message @}</label>
                <textarea name="contact_message" rows="6" required>{{ $inputMessage }}</textarea>
            </div>
            <button type="submit" class="hbp-btn-submit">{@ hbp-contact.send @}</button>
        </form>
    {% endif %}
</div>

{% include 'footer' %}
