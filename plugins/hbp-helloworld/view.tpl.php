<?php /* SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE */ ?>
{% include 'header' %}

<article class="hbp-single-post">
    <header class="hbp-article-header">
        <h1 class="hbp-article-title">{@ hbp-helloworld.title @}</h1>
    </header>

    <div class="hbp-content">
        <p>{@ hbp-helloworld.body @}</p>
        <p>{@ hbp-helloworld.description @}</p>
        <hr>
        <p><a href="/">{@ back_to_top @}</a></p>
    </div>
</article>

{% include 'footer' %}
