<?php

namespace App\Console\Commands;

use App\Digest\WeatherEmoji;
use App\Mail\ConditionsPreviewMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Dev tool — email one reference sheet of every WeatherAPI condition with the
 * icon {@see WeatherEmoji} maps it to, so the emoji coverage can be eyeballed in
 * Mailtrap. The catalog is WeatherAPI's published `weather_conditions.json`
 * (daytime text), pinned here so the command needs no network. Never runs in
 * production.
 */
#[Signature('digests:conditions {--email=preview@tripcast.test : Recipient mailbox}')]
#[Description('Email a reference sheet of every WeatherAPI condition + its mapped icon (visual testing).')]
class PreviewConditions extends Command
{
    /**
     * WeatherAPI condition code => daytime condition text
     * (https://www.weatherapi.com/docs/weather_conditions.json).
     *
     * @var array<int, string>
     */
    private const CATALOG = [
        1000 => 'Sunny',
        1003 => 'Partly cloudy',
        1006 => 'Cloudy',
        1009 => 'Overcast',
        1012 => 'Haze',
        1015 => 'Dust haze',
        1018 => 'Blowing dust',
        1021 => 'Dust storm',
        1024 => 'Sandstorm',
        1027 => 'Severe sandstorm',
        1030 => 'Mist',
        1033 => 'Smoke',
        1036 => 'Smoky haze',
        1039 => 'Smog',
        1042 => 'Severe smog',
        1045 => 'Saharan dust',
        1048 => 'Dust',
        1063 => 'Patchy rain possible',
        1066 => 'Patchy snow possible',
        1069 => 'Patchy sleet possible',
        1072 => 'Patchy freezing drizzle possible',
        1087 => 'Thundery outbreaks possible',
        1114 => 'Blowing snow',
        1117 => 'Blizzard',
        1135 => 'Fog',
        1147 => 'Freezing fog',
        1150 => 'Patchy light drizzle',
        1153 => 'Light drizzle',
        1168 => 'Freezing drizzle',
        1171 => 'Heavy freezing drizzle',
        1180 => 'Patchy light rain',
        1183 => 'Light rain',
        1186 => 'Moderate rain at times',
        1189 => 'Moderate rain',
        1192 => 'Heavy rain at times',
        1195 => 'Heavy rain',
        1198 => 'Light freezing rain',
        1201 => 'Moderate or heavy freezing rain',
        1204 => 'Light sleet',
        1207 => 'Moderate or heavy sleet',
        1210 => 'Patchy light snow',
        1213 => 'Light snow',
        1216 => 'Patchy moderate snow',
        1219 => 'Moderate snow',
        1222 => 'Patchy heavy snow',
        1225 => 'Heavy snow',
        1237 => 'Ice pellets',
        1240 => 'Light rain shower',
        1243 => 'Moderate or heavy rain shower',
        1246 => 'Torrential rain shower',
        1249 => 'Light sleet showers',
        1252 => 'Moderate or heavy sleet showers',
        1255 => 'Light snow showers',
        1258 => 'Moderate or heavy snow showers',
        1261 => 'Light showers of ice pellets',
        1264 => 'Moderate or heavy showers of ice pellets',
        1273 => 'Patchy light rain with thunder',
        1276 => 'Moderate or heavy rain with thunder',
        1279 => 'Patchy light snow with thunder',
        1282 => 'Moderate or heavy snow with thunder',
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $conditions = $this->conditions();

        Mail::to($email)->send(new ConditionsPreviewMail($conditions));

        $unmapped = array_values(array_filter($conditions, fn (array $c): bool => $c['emoji'] === ''));

        $this->info('Sent the condition-icon reference sheet ('.count($conditions)." conditions) to {$email}.");

        if ($unmapped !== []) {
            $this->warn(count($unmapped).' condition(s) have no icon: '
                .implode(', ', array_map(fn (array $c): string => $c['text'], $unmapped)));
        }

        return self::SUCCESS;
    }

    /**
     * The catalog projected into render rows: provider code, condition text, and
     * the icon {@see WeatherEmoji} resolves for it.
     *
     * @return list<array{code: int, text: string, emoji: string}>
     */
    private function conditions(): array
    {
        $rows = [];

        foreach (self::CATALOG as $code => $text) {
            $rows[] = [
                'code' => $code,
                'text' => $text,
                'emoji' => WeatherEmoji::for($text),
            ];
        }

        return $rows;
    }
}
