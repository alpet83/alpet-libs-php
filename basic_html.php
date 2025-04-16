<?php
    function button_link(string $text, string $link) {
        return "<input type='button' onClick='document.location=\"$link\"' value='$text' />\n";
    }