<?php

use App\Models\Setting;

if (!function_exists('setting')) {
    function setting($key = null, $default = null)
    {
        if (is_null($key)) {
            return new Setting;
        }

        return Setting::get($key, $default);
    }
}

if (!function_exists('number_to_taka_words')) {
    function number_to_taka_words($n)
    {
        $n = (int) round($n);
        if ($n === 0) return 'Zero Taka Only';
        $ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten",
                 "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
        $tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
        $convert = function ($num) use (&$convert, $ones, $tens) {
            if ($num < 20) return $ones[$num];
            if ($num < 100) return $tens[intval($num / 10)] . ($num % 10 !== 0 ? " " . $ones[$num % 10] : "");
            if ($num < 1000) return $ones[intval($num / 100)] . " Hundred" . ($num % 100 !== 0 ? " and " . $convert($num % 100) : "");
            if ($num < 100000) return $convert(intval($num / 1000)) . " Thousand" . ($num % 1000 !== 0 ? " " . $convert($num % 1000) : "");
            if ($num < 10000000) return $convert(intval($num / 100000)) . " Lakh" . ($num % 100000 !== 0 ? " " . $convert($num % 100000) : "");
            return $convert(intval($num / 10000000)) . " Crore" . ($num % 10000000 !== 0 ? " " . $convert($num % 10000000) : "");
        };
        return trim($convert($n)) . " Taka Only";
    }
}
