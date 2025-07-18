<?php

namespace App\Filament\Admin\Pages;


use Illuminate\Support\HtmlString;
use App\Models\GamesKey;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;

class GamesKeyPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.games-key-page';

    protected static ?string $title = 'Chaves dos Jogos';

    // protected static ?string $slug = 'chaves-dos-jogos';

    /**
     * @dev @mscodex
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }


    public ?array $data = [];
    public ?GamesKey $setting;

    /**
     * @return void
     */
    public function mount(): void
    {
        $gamesKey = GamesKey::first();
        if(!empty($gamesKey)) {
            $this->setting = $gamesKey;
            $this->form->fill($this->setting->toArray());
        }else{
            $this->form->fill();
        }
    }

    /**
     * @param Form $form
     * @return Form
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                
                Section::make('API GAMES')
                    ->description(new HtmlString('Para pegar suas credenciais, contate -  <a href="https://easyconnect.games/" target="_blank" style="color: blue;">Clique aqui!</a>'))
                    ->schema([
                        TextInput::make('venix_agent_code')
                            ->label('Agent Code')
                            ->placeholder('Digite aqui o Agent Code')
                            ->maxLength(191),
                        TextInput::make('venix_agent_token')
                            ->label('Agent Token')
                            ->placeholder('Digite aqui o Agent Token')
                            ->maxLength(191),
                        TextInput::make('venix_agent_secret')
                            ->label('Agent Secret')
                            ->placeholder('Digite aqui a Agente Secret')
                            ->maxLength(191),
                    ])
                    ->columns(1),

                // Section::make('API SPORTS')
                //     ->description('Ajustes de credenciais para a API SPORTS')
                //     ->schema([
                //         TextInput::make('playconnect_code')
                //             ->label('Agent Code')
                //             ->placeholder('Digite aqui o Agent Code')
                //             ->maxLength(191),
                //         TextInput::make('playconnect_token')
                //             ->label('Agent Token')
                //             ->placeholder('Digite aqui o Agent Token')
                //             ->maxLength(191),
                //         TextInput::make('playconnect_secret_key')
                //             ->label('Agent Secret')
                //             ->placeholder('Digite aqui a Agente Secret')
                //             ->maxLength(191),
                //     ])
                //     ->columns(3),

            ])
            ->statePath('data');
    }


    /**
     * @return void
     */
    public function submit(): void
    {
        try {
            if(env('APP_DEMO')) {
                Notification::make()
                    ->title('Atenção')
                    ->body('Você não pode realizar está alteração na versão demo')
                    ->danger()
                    ->send();
                return;
            }

            $setting = GamesKey::first();
            if(!empty($setting)) {
                if($setting->update($this->data)) {
                    Notification::make()
                        ->title('Chaves Alteradas')
                        ->body('Suas chaves foram alteradas com sucesso!')
                        ->success()
                        ->send();
                }
            }else{
                if(GamesKey::create($this->data)) {
                    Notification::make()
                        ->title('Chaves Criadas')
                        ->body('Suas chaves foram criadas com sucesso!')
                        ->success()
                        ->send();
                }
            }


        } catch (Halt $exception) {
            Notification::make()
                ->title('Erro ao alterar dados!')
                ->body('Erro ao alterar dados!')
                ->danger()
                ->send();
        }
    }
}
