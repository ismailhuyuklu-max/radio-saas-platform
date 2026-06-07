namespace AdCastPro.SyncClient.UI.Services;

/// <summary>Window navigation helper — DI'den window al, ShowDialog.</summary>
public sealed class NavigationService
{
    private readonly IServiceProvider _services;
    public NavigationService(IServiceProvider services) => _services = services;

    public T Resolve<T>() where T : notnull => (T)_services.GetService(typeof(T))!;
}
