<?php

namespace App\Utils;

use App\Services\ChatAssistant;
use Dotenv\Dotenv;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;
use function Termwind\render;

class OnBoardingSteps
{
    private string $configFile = '.droid_config';

    /**
     * @throws Exception
     */
    public function isCompleted(): bool
    {
        return $this->configurationFileExists()
            && $this->viewsFolderExists()
            && $this->APIKeyExists()
            && $this->modelExists()
            && $this->promptExists()
            && $this->assistantExists();
    }

    private function viewsFolderExists(): bool
    {
        if (! Storage::disk('home')->exists('.droid_views')) {
            try {
                Storage::disk('home')->makeDirectory('.droid_views');
            } catch (Exception $ex) {
                return false;
            }
        }

        Config::set('view.compiled', Storage::disk('home')->path('.droid_views'));

        return true;
    }

    /**
     * @throws Exception
     */
    private function modelExists(): bool
    {
        if (! config('droid.model')) {
            $model = select(
                label: '🤖 Choose the default Model for the assistant',
                options: ['gpt-4o', 'gpt-4-turbo', 'gpt-4-turbo-preview	', 'gpt-3.5-turbo'],
                default: 'gpt-4o',
                hint: 'The model to use for the assistant. You can change this later in the configuration file'
            );

            $this->setConfigValue('DROID_MODEL', $model);
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function promptExists(): bool
    {
        if (! config('droid.prompt')) {
            $prompt = textarea(
                label: '🤖: Enter the prompt to use for the assistant',
                default: config('droid.default_prompt'),
                rows: 20
            );

            $this->setConfigValue('DROID_PROMPT', $prompt);
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function configurationFileExists(): bool
    {
        if (! Storage::disk('home')->exists($this->configFile)) {
            try {
                // create the config file from the internal config file
                Storage::disk('home')->put($this->configFile, '');
            } catch (Exception $ex) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function APIKeyExists(): bool
    {
        if (! config('droid.api_key')) {
            $apiKey = password(
                label: '🤖: Enter your OpenAI API key to continue',
                placeholder: 'sk-xxxxxx-xxxxxx-xxxxxx-xxxxxx',
                hint: 'You can find your API key in your OpenAI dashboard'
            );
            $this->setConfigValue('DROID_API_KEY', $apiKey);
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function assistantExists(): bool
    {
        $chatAssistant = new ChatAssistant;

        if (! config('droid.assistant_id')) {

            $confirmed = confirm(
                label: 'No assistant found. Do you want to create an assistant now?',
                yes: 'I accept',
                no: 'I decline',
                hint: 'This will create an assistant on OpenAI with the provided API key'
            );

            if (! $confirmed) {
                render(view('assistant', [
                    'answer' => 'Okay, you can always run `droid` to set up your assistant later',
                ]));

                return false;
            }

            $response = spin(
                fn () => $chatAssistant->createAssistant(),
                'Creating an assistant...'
            );

            if (! $response) {
                error('Failed to create the assistant');

                return false;
            }
            $this->setConfigValue('DROID_ASSISTANT_ID', $response->id);
            render(view('assistant', [
                'answer' => $response->name.' has been created successfully 🎉',
            ]));

            return true;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    protected function setConfigValue($key, $value): bool
    {
        if (! $this->configurationFileExists()) {
            error('Failed to set the configuration value');

            return false;
        }

        if (strpos($value, "\n") !== false) {
            // Wrap multiline value in double quotes
            $value = '"'.addslashes($value).'"';
        }

        $config = Storage::disk('home')->get($this->configFile);
        $pattern = "/^{$key}=.*/m";

        if (preg_match($pattern, $config)) {
            // Key exists, replace it with new value
            $config = preg_replace($pattern, "{$key}={$value}", $config);
        } else {
            // Key does not exist, append it
            $config .= "\n{$key}={$value}";
        }

        if (Storage::disk('home')->put($this->configFile, $config)) {
            // Reload the environment file
            $this->loadConfigFile();

            return true;
        }

        error('Failed to set the configuration value');

        return false;
    }

    /**
     * @throws Exception
     */
    public function loadConfigFile(): bool
    {
        $this->configurationFileExists();

        $path = Storage::disk('home')->path($this->configFile);
        if (Storage::disk('home')->exists($this->configFile)) {
            $dotenv = Dotenv::createImmutable(dirname($path), basename($path));
            $envValues = $dotenv->load();

            foreach ($envValues as $key => $value) {
                $parsedKey = strtolower(str_replace('DROID_', '', $key));
                Config::set('droid.'.$parsedKey, $value);
            }

            return true;
        }

        return false;
    }
}
