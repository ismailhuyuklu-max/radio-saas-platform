using Microsoft.Win32;

namespace AdCastPro.SyncClient.UI.ViewModels;

/// <summary>
/// Stable machine ID — Windows kayıt defterinde saklanan UUID.
/// Aynı PC'de aynı kullanıcı tekrar login yaptığında değişmez.
/// Format: HKCU\Software\AdCastPro\MachineId
/// </summary>
public static class MachineIdProvider
{
    private const string KeyPath = @"Software\AdCastPro";
    private const string ValueName = "MachineId";

    public static string GetOrCreate()
    {
        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(KeyPath, writable: true);
            var existing = key?.GetValue(ValueName) as string;
            if (!string.IsNullOrEmpty(existing)) return existing;

            var newId = Guid.NewGuid().ToString("N");
            key?.SetValue(ValueName, newId, RegistryValueKind.String);
            return newId;
        }
        catch
        {
            // Registry erişimi yoksa session-scoped fallback
            return Guid.NewGuid().ToString("N");
        }
    }
}
