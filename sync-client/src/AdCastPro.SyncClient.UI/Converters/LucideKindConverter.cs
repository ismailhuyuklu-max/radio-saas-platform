using System.Globalization;
using System.Windows.Data;
using MahApps.Metro.IconPacks;

namespace AdCastPro.SyncClient.UI.Converters;

/// <summary>
/// Tag string'ini (ör. "House") PackIconLucideKind'a çevirir — sidebar gibi
/// yerlerde ikonu Tag üzerinden tanımlayabilmek için. Eşleşmezse None.
/// </summary>
public sealed class LucideKindConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
    {
        if (value is string s && Enum.TryParse<PackIconLucideKind>(s, ignoreCase: true, out var kind))
            return kind;
        return PackIconLucideKind.None;
    }

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
