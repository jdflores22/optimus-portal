using FluentValidation;
using Microsoft.Extensions.DependencyInjection;
using Optimus.Application.Auth;

namespace Optimus.Application;

public static class DependencyInjection
{
    public static IServiceCollection AddApplication(this IServiceCollection services)
    {
        services.AddValidatorsFromAssemblyContaining<LoginRequestValidator>();
        return services;
    }
}
