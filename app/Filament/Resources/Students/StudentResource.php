<?php

namespace App\Filament\Resources\Students;

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\Schemas\StudentForm;
use App\Filament\Resources\Students\Tables\StudentsTable;
use App\Models\Student;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $slug = 'alumnes';

    protected static ?string $modelLabel = 'alumne';

    protected static ?string $pluralModelLabel = 'alumnes';

    protected static ?string $recordTitleAttribute = 'nom_complet';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Alumnes';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    public static function form(Schema $schema): Schema
    {
        return StudentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/nou'),
            'edit' => EditStudent::route('/{record}/fitxa'),
        ];
    }
}
