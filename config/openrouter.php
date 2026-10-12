<?php
// config/openrouter.php

define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent');
define('GEMINI_MODEL', 'gemini-3.5-flash-lite');