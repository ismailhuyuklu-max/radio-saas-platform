using System.Globalization;
using System.Windows;
using System.Windows.Data;

namespace AdCastPro.SyncClient.UI.Converters;

/// <summary>
/// Aktif bölüm adı (ConverterParameter) seçili bölüme eşitse Visible, değilse Collapsed.
/// Sidebar navigasyonunda içerik panellerini değiştirmek için.
/// </summary>
public sealed class SectionToVisibilityConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
    {
        var current = value as string;
        var target = parameter as string;
        return string.Equals(current, target, StringComparison.OrdinalIgnoreCase)
            ? Visibility.Visible
            : Visibility.Collapsed;
    }

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
