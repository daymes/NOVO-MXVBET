<?php

namespace App\Filament\Admin\Pages;

use App\Models\CustomLayout;
use Creagia\FilamentCodeField\CodeField;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Cache;

class LayoutCssCustom extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.layout-css-custom';

    protected static ?string $navigationLabel = 'Customização Layout';

    protected static ?string $modelLabel = 'Customização Layout';

    protected static ?string $title = 'Customização Layout';

    protected static ?string $slug = 'custom-layout';

    public ?array $data = [];
    public CustomLayout $custom;

    /**
     * @dev @mscodex
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    /**
     * @return void
     */
    public function mount(): void
    {
        $this->custom = CustomLayout::first();
        $this->form->fill($this->custom->toArray());
    }

    /**
     * @param array $data
     * @return array
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {

        return $data;
    }

    /**
     * @param Form $form
     * @return Form
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->label('Fondo')
                    ->schema([
                        // ColorPicker::make('background_base')
                        //     ->label('Fondo Principal')
                        //     ->required(),
                        ColorPicker::make('background_base_dark')
                            ->label('Backgroud')
                            ->required(),
                        // ColorPicker::make('carousel_banners')
                        //     ->label('Banners de Carrusel')
                        //     ->required(),
                        // ColorPicker::make('carousel_banners_dark')
                        //     ->label('Banners de Carrusel (Oscuro)')
                        //     ->required(),
                    ])->columns(1)
                ,
                Section::make('Sidebar & Navbar & Footer')
                    ->description('Personalize a aparência do seu site, conferindo-lhe uma identidade única.')
                    ->collapsible()
                    ->collapsed(true)
                    ->schema([
                        ColorPicker::make('sidebar_color')
                            ->label('Icones do Sidebar')
                            ->required(),

                        ColorPicker::make('sidebar_color_dark')
                            ->label('Sidebar')
                            ->required(),

                        // ColorPicker::make('navtop_color')
                        //     ->label('Barra Superior de Navegación')
                        //     ->required(),

                        ColorPicker::make('navtop_color_dark')
                            ->label('Navtop')
                            ->required(),

                        // ColorPicker::make('side_menu')
                        //     ->label('Caja de Menú Lateral')
                        //     ->required(),

                        // ColorPicker::make('side_menu_dark')
                        //     ->label('Caja de Menú Lateral')
                        //     ->required(),

                        // ColorPicker::make('footer_color')
                        //     ->label('Color del Pie de Página')
                        //     ->required(),

                        ColorPicker::make('footer_color_dark')
                            ->label('Footer')
                            ->required(),
                    ])->columns(4)
                ,
                Section::make('Customização')
                    ->description('Personalize a aparência do seu site, conferindo-lhe uma identidade única.')
                    ->collapsible()
                    ->collapsed(true)
                    ->schema([
                        ColorPicker::make('primary_color')
                            ->label('Color Primária')
                            ->required(),
                        ColorPicker::make('primary_opacity_color')
                            ->label('Cor Secundária')
                            ->required(),

                        // ColorPicker::make('input_primary')
                        //     ->label('Color Principal de Entrada')
                        //     ->required(),
                        // ColorPicker::make('input_primary_dark')
                        //     ->label('Color Principal de Entrada (Oscura)')
                        //     ->required(),

                        // ColorPicker::make('card_color')
                        //     ->label('Color Principal de Tarjeta')
                        //     ->required(),
                        // ColorPicker::make('card_color_dark')
                        //     ->label('Color Principal de Tarjeta (Oscura)')
                        //     ->required(),

                        // ColorPicker::make('secundary_color')
                        //     ->label('Color Secundario')
                        //     ->required(),

                        ColorPicker::make('gray_dark_color')
                            ->label('Cor dos (input)')
                            ->required(),

                        // ColorPicker::make('gray_light_color')
                        //     ->label('Color Gris Claro')
                        //     ->required(),
                        
                        ColorPicker::make('title_color')
                            ->label('Cor do Título')
                            ->required(),
                        ColorPicker::make('text_color')
                            ->label('Cor do Texto')
                            ->required(),

                        ColorPicker::make('gray_medium_color')
                            ->label('Bacground do saldo')
                            ->required(),
                        // ColorPicker::make('gray_over_color')
                        //     ->label('Color Gris Sobrepuesto')
                        //     ->required(),

                        // ColorPicker::make('sub_text_color')
                        //     ->label('Color del Subtexto')
                        //     ->required(),
                        // ColorPicker::make('placeholder_color')
                        //     ->label('Color del Marcador de Posición')
                        //     ->required(),
                        // ColorPicker::make('background_color')
                        //     ->label('Color de Fondo')
                        //     ->required(),
                        TextInput::make('border_radius')
                            ->label('Border')
                            ->required(),
                    ])->columns(3)
                ,
                Section::make('Customização no Código HTML BASE')
                    ->description('Customize seu css, js, ou adicione conteúdo no corpo da sua página')
                    ->collapsible()
                    ->collapsed(true)
                    ->schema([
                        CodeField::make('custom_css')
                            ->label('Customização do CSS')
                            ->setLanguage(CodeField::CSS)
                            ->withLineNumbers()
                            ->minHeight(400),
                        CodeField::make('custom_js')
                            ->label('Customização do JS')
                            ->setLanguage(CodeField::JS)
                            ->withLineNumbers()
                            ->minHeight(400),
                        CodeField::make('custom_header')
                            ->label('Customização do Header')
                            ->setLanguage(CodeField::HTML)
                            ->withLineNumbers()
                            ->minHeight(400),
                        CodeField::make('custom_body')
                            ->label('Customização do Body')
                            ->setLanguage(CodeField::HTML)
                            ->withLineNumbers()
                            ->minHeight(400),
                    ])
                ,
                Section::make('Links Sociais')
                    ->description('Personalize os links das suas redes sociais')
                    ->collapsible()
                    ->collapsed(true)
                    ->schema([
                        TextInput::make('instagram')
                            ->label('Instagram')
                            ->placeholder('Insira a URL do seu Instagram')
                            ->url()
                            ->maxLength(191),
                        TextInput::make('facebook')
                            ->label('Facebook')
                            ->placeholder('Insira a URL do seu Facebook')
                            ->url()
                            ->maxLength(191),
                        TextInput::make('telegram')
                            ->label('Telegram')
                            ->placeholder('Insira a URL do seu Telegram')
                            ->url()
                            ->maxLength(191),
                        TextInput::make('twitter')
                            ->label('Twitter')
                            ->placeholder('Insira a URL do seu Twitter')
                            ->url()
                            ->maxLength(191),
                        TextInput::make('whastapp')
                            ->label('Whatsapp')
                            ->placeholder('Insira a URL do seu Whatsapp')
                            ->url()
                            ->maxLength(191),
                        TextInput::make('youtube')
                            ->label('YouTube')
                            ->placeholder('Insira a URL do seu YouTube')
                            ->url()
                            ->maxLength(191),
                    ])->columns(2)

                ,
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

            $custom = CustomLayout::first();

            if(!empty($custom)) {
                if($custom->update($this->data)) {

                    Cache::put('custom', $custom);

                    Notification::make()
                        ->title('Dados alterados')
                        ->body('Dados alterados com sucesso!')
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
