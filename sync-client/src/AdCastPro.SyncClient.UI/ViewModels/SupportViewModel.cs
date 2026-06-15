using System.Net.Http;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Models;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;

namespace AdCastPro.SyncClient.UI.ViewModels;

/// <summary>
/// Destek formu. Manuel alanlar (ad/telefon/e-posta/mesaj) + otomatik radyo
/// bilgileri (salt-okunur). Radyo bilgileri sunucuda dogrulanir.
/// </summary>
public sealed partial class SupportViewModel : ObservableObject
{
    private readonly IApiClient _api;

    public SupportViewModel(IApiClient api) => _api = api;

    // Manuel alanlar
    [ObservableProperty] private string _fullName = "";
    [ObservableProperty] private string _phone = "";
    [ObservableProperty] private string _email = "";
    [ObservableProperty] private string _message = "";

    // Otomatik (salt-okunur) radyo bilgileri
    [ObservableProperty] private string _radioName = "—";
    [ObservableProperty] private string _frequency = "—";
    [ObservableProperty] private string _region = "—";
    [ObservableProperty] private string _city = "—";
    [ObservableProperty] private bool _radioInfoAvailable = true;

    // Durum
    [ObservableProperty] private bool _isSubmitting;
    [ObservableProperty] private bool _hasStatus;
    [ObservableProperty] private bool _isError;
    [ObservableProperty] private string _statusMessage = "";

    /// <summary>Giris yapan yayincinin radyo bilgilerini doldurur (MainViewModel cagirir).</summary>
    public void SetRadioInfo(string? name, string? frequency, string? region, string? city)
    {
        var available = !string.IsNullOrWhiteSpace(name);
        RadioInfoAvailable = available;
        RadioName = string.IsNullOrWhiteSpace(name) ? "—" : name!;
        Frequency = string.IsNullOrWhiteSpace(frequency) ? "—" : frequency!;
        Region = string.IsNullOrWhiteSpace(region) ? "—" : region!;
        City = string.IsNullOrWhiteSpace(city) ? "—" : city!;
    }

    [RelayCommand]
    private async Task SubmitAsync()
    {
        if (IsSubmitting) return;

        if (!RadioInfoAvailable)
        {
            SetStatus("Radyo bilgileri alınamadı. Lütfen oturumunuzu yenileyip tekrar deneyin.", true);
            return;
        }

        var error = Validate();
        if (error != null)
        {
            SetStatus(error, true);
            return;
        }

        IsSubmitting = true;
        HasStatus = false;
        try
        {
            await _api.CreateSupportTicketAsync(new SupportTicketRequest(
                FullName.Trim(), Phone.Trim(), Email.Trim(), Message.Trim()));

            SetStatus("Destek talebiniz başarıyla iletildi. Ekibimiz en kısa sürede sizinle iletişime geçecektir.", false);
            FullName = "";
            Phone = "";
            Email = "";
            Message = "";
        }
        catch (HttpRequestException ex)
        {
            // Sunucu mesaji (validasyon / rate-limit) varsa onu goster, yoksa genel hata.
            var msg = string.IsNullOrWhiteSpace(ex.Message)
                ? "Destek talebi gönderilemedi. Lütfen bilgilerinizi kontrol edip tekrar deneyin."
                : ex.Message;
            SetStatus(msg, true);
        }
        catch
        {
            SetStatus("Destek talebi gönderilemedi. Lütfen bilgilerinizi kontrol edip tekrar deneyin.", true);
        }
        finally
        {
            IsSubmitting = false;
        }
    }

    private string? Validate()
    {
        if (string.IsNullOrWhiteSpace(FullName)) return "Ad Soyad zorunludur.";
        if (string.IsNullOrWhiteSpace(Phone)) return "Telefon zorunludur.";
        if (string.IsNullOrWhiteSpace(Email) || !IsValidEmail(Email)) return "Geçerli bir e-posta adresi girin.";
        if ((Message?.Trim().Length ?? 0) < 10) return "Destek mesajı en az 10 karakter olmalıdır.";
        return null;
    }

    private static bool IsValidEmail(string email)
    {
        try
        {
            var addr = new System.Net.Mail.MailAddress(email.Trim());
            return addr.Address == email.Trim() && email.Contains('.');
        }
        catch
        {
            return false;
        }
    }

    private void SetStatus(string message, bool isError)
    {
        StatusMessage = message;
        IsError = isError;
        HasStatus = true;
    }
}
